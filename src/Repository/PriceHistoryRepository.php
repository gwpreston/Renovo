<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\PriceChange;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Price history, scoped exactly like the subscriptions it describes.
 *
 * There is no update method and no delete method, and that is the design
 * rather than an omission — the table is append-only. Rows disappear only when
 * their subscription does, by cascade.
 */
final class PriceHistoryRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'subscription_price_history';
    }

    /**
     * Widened for reads by the same participant rule as the subscription
     * itself, because "scoped exactly like the subscriptions it describes"
     * has to include the exception or it is not the same scoping.
     *
     * A history row carries the subscription owner's id, so in ISOLATED mode a
     * split participant would otherwise open a subscription they can see and
     * be told its price has never been recorded — a statement that is false,
     * about a bill they are paying part of, with a scheduled rise they have no
     * way to find out about.
     *
     * Read-only, as everywhere. There is no write path through this hook: the
     * table has no update or delete, and the only insert goes through a caller
     * that must first prove it may write to the subscription.
     *
     * @param array<string, mixed> $params
     */
    protected function readVisibilityPredicate(Scope $scope, array &$params): string
    {
        $params['__participant'] = $scope->userId;

        return 'EXISTS (SELECT 1 FROM ' . $this->quote('subscription_splits') . ' split'
            . ' WHERE split.' . $this->quote('subscription_id') . ' = ' . $this->qualify('subscription_id')
            . ' AND split.' . $this->quote('user_id') . ' = :__participant)';
    }

    protected function filterableColumns(): array
    {
        return [
            'id',
            'subscription_id',
            'household_id',
            'owner_user_id',
            'price_minor',
            'currency',
            'effective_from',
            'source',
            'created_at',
        ];
    }

    /**
     * Every recorded price for one subscription, oldest first.
     *
     * @return list<PriceChange>
     */
    public function findForSubscription(Scope $scope, int $subscriptionId): array
    {
        $criteria = Criteria::new()
            ->equals('subscription_id', $subscriptionId)
            ->orderBy('effective_from', 'asc')
            ->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithAuthor()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /**
     * The price in force on a given date — the latest row that has taken
     * effect by then.
     */
    public function findEffectiveOn(Scope $scope, int $subscriptionId, DateTimeImmutable $date): ?PriceChange
    {
        $criteria = Criteria::new()
            ->equals('subscription_id', $subscriptionId)
            ->where('effective_from', '<=', $date->format('Y-m-d'))
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc');

        $params = [];
        $sql = $this->selectWithAuthor()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria)
            . ' LIMIT 1';

        $row = $this->db->fetchOne($sql, $params);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The next announced-but-not-yet-applied change for one subscription.
     */
    public function findNextScheduled(Scope $scope, int $subscriptionId, DateTimeImmutable $after): ?PriceChange
    {
        $criteria = Criteria::new()
            ->equals('subscription_id', $subscriptionId)
            ->where('effective_from', '>', $after->format('Y-m-d'))
            ->orderBy('effective_from', 'asc')
            ->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithAuthor()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria)
            . ' LIMIT 1';

        $row = $this->db->fetchOne($sql, $params);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Every future-dated change in scope, for the forecast and the dashboard.
     *
     * @return array<int, list<PriceChange>> Keyed by subscription id.
     */
    public function findScheduledAfter(Scope $scope, DateTimeImmutable $after): array
    {
        $criteria = Criteria::new()
            ->where('effective_from', '>', $after->format('Y-m-d'))
            ->orderBy('effective_from', 'asc')
            ->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithAuthor()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        $grouped = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $grouped[(int) $row['subscription_id']][] = $this->hydrate($row);
        }

        return $grouped;
    }

    /**
     * Every recorded price in scope, grouped by subscription and oldest first
     * within each group.
     *
     * One query for what `findForSubscription()` answers one subscription at a
     * time. The insight rules ask "has this price moved, and is another move
     * announced" of every subscription on the analytics screen at once, and
     * asking per subscription would be a query per row on a page that already
     * walks the household three times.
     *
     * It reads the whole history rather than a recent window because a rise is
     * a comparison between two rows: a window that held the new price but not
     * the one before it could not tell a rise from a first price. The table is
     * append-only and a subscription accumulates a row per price change, so
     * this is a handful of rows per subscription, not a ledger.
     *
     * @return array<int, list<PriceChange>> Keyed by subscription id.
     */
    public function findAllBySubscription(Scope $scope): array
    {
        $criteria = Criteria::new()
            ->orderBy('subscription_id', 'asc')
            ->orderBy('effective_from', 'asc')
            ->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithAuthor()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        $grouped = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $grouped[(int) $row['subscription_id']][] = $this->hydrate($row);
        }

        return $grouped;
    }

    /**
     * Subscriptions whose effective price no longer matches the price stored on
     * the subscription row itself.
     *
     * This is how a scheduled increase becomes real. `subscriptions.price_minor`
     * is a denormalisation of "the latest history row that has taken effect" —
     * it exists because the list view sorts and filters on it — and this query
     * finds the rows where the denormalisation has fallen behind, which is
     * exactly the set the catch-up needs to write.
     *
     * Returning only divergent rows matters: on the overwhelming majority of
     * page views the answer is empty and no write happens at all.
     *
     * The "current" row is the one with the greatest effective date, ties
     * broken by the greatest id. It is deliberately not MAX(id): a change
     * scheduled for next month is recorded before a correction applied today,
     * so insertion order and effective order are not the same thing.
     *
     * @return list<array{subscription_id: int, price_minor: int, currency: string}>
     */
    public function findDivergentCurrentPrices(Scope $scope, DateTimeImmutable $today): array
    {
        $history = $this->quote('subscription_price_history');

        // The same date is bound twice under two names rather than once under
        // one. MySQL's native prepared statements — which this application
        // insists on, because emulation reintroduces client-side interpolation
        // — reject a named placeholder used more than once in a statement.
        // PostgreSQL permits it, so this is exactly the kind of difference that
        // passes locally and fails on the other engine.
        $params = [
            '__today' => $today->format('Y-m-d'),
            '__today_inner' => $today->format('Y-m-d'),
        ];

        $sql = 'SELECT ' . $this->qualify('subscription_id') . ' AS subscription_id,'
            . ' ' . $this->qualify('price_minor') . ' AS price_minor,'
            . ' ' . $this->qualify('currency') . ' AS currency'
            . ' FROM ' . $history
            . ' INNER JOIN ' . $this->quote('subscriptions') . ' s'
            . ' ON s.' . $this->quote('id') . ' = ' . $this->qualify('subscription_id')
            . ' WHERE ' . $this->scopePredicate($scope, $params)
            . ' AND ' . $this->qualify('effective_from') . ' <= :__today'
            . ' AND ' . $this->qualify('id') . ' = ('
            . 'SELECT latest.' . $this->quote('id') . ' FROM ' . $history . ' latest'
            . ' WHERE latest.' . $this->quote('subscription_id') . ' = ' . $this->qualify('subscription_id')
            . ' AND latest.' . $this->quote('effective_from') . ' <= :__today_inner'
            . ' ORDER BY latest.' . $this->quote('effective_from') . ' DESC,'
            . ' latest.' . $this->quote('id') . ' DESC LIMIT 1)'
            . ' AND (s.' . $this->quote('price_minor') . ' <> ' . $this->qualify('price_minor')
            . ' OR s.' . $this->quote('currency') . ' <> ' . $this->qualify('currency') . ')'
            . ' ORDER BY ' . $this->qualify('subscription_id') . ' ASC';

        return array_map(
            static fn (array $row): array => [
                'subscription_id' => (int) $row['subscription_id'],
                'price_minor' => (int) $row['price_minor'],
                'currency' => (string) $row['currency'],
            ],
            $this->db->fetchAll($sql, $params),
        );
    }

    /**
     * Append a price to a subscription's history.
     *
     * The household and owner columns are supplied by the scoping layer, not by
     * the caller, so a history row cannot be attached to somebody else's
     * subscription even if the id were guessed — the caller must also be able
     * to see the subscription, which the service checks first.
     */
    public function append(
        Scope $scope,
        int $subscriptionId,
        Money $price,
        DateTimeImmutable $effectiveFrom,
        PriceChangeSource $source,
        ?string $note = null,
        ?int $ownerUserId = null,
    ): int {
        return $this->insertScoped($scope, [
            'subscription_id' => $subscriptionId,
            'price_minor' => $price->amountMinor,
            'currency' => $price->currency,
            'effective_from' => $effectiveFrom->format('Y-m-d'),
            'source' => $source->value,
            'note' => $note,
            'created_by_user_id' => $scope->userId,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            // A subscription owned by another member in SHARED mode must have
            // its history owned by the same member, or the two would disagree
            // the moment the instance switched to ISOLATED.
            'owner_user_id' => $ownerUserId ?? $scope->userId,
        ]);
    }

    private function selectWithAuthor(): string
    {
        $table = $this->quote('subscription_price_history');

        return 'SELECT ' . $table . '.*,'
            . ' author.' . $this->quote('display_name') . ' AS created_by_name'
            . ' FROM ' . $table
            . ' LEFT JOIN ' . $this->quote('users') . ' author'
            . ' ON author.' . $this->quote('id') . ' = ' . $this->qualify('created_by_user_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PriceChange
    {
        return new PriceChange(
            id: (int) $row['id'],
            subscriptionId: (int) $row['subscription_id'],
            price: Money::of((int) $row['price_minor'], (string) $row['currency']),
            effectiveFrom: new DateTimeImmutable((string) $row['effective_from']),
            source: PriceChangeSource::tryFrom((string) $row['source']) ?? PriceChangeSource::Manual,
            note: ($row['note'] ?? null) === null || $row['note'] === '' ? null : (string) $row['note'],
            createdByName: ($row['created_by_name'] ?? null) === null ? null : (string) $row['created_by_name'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
