<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BillingCycle;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\Tag;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\SplitMode;
use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionType;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Subscriptions are the application's personal data, so this repository is
 * fully scoped: every statement below is assembled by the scoped base class
 * and carries the household (and, in ISOLATED mode, the owner) predicate.
 *
 * The joins to categories, users and tags are read-only decorations of rows
 * that have already passed the predicate; the tag lookup re-applies it anyway
 * rather than trusting a list of ids it was handed.
 */
final class SubscriptionRepository extends AbstractScopedRepository
{
    /**
     * Symbolic sort key => real column. A sort key that is not in this map is
     * rejected, which is why a query-string value can never reach the SQL.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'name' => 'name',
        'next_payment' => 'next_payment_date',
        'price' => 'price_minor',
        'created' => 'created_at',
    ];

    protected function table(): string
    {
        return 'subscriptions';
    }

    /**
     * A member listed on a shared-cost split may see the subscription they are
     * paying part of, even in ISOLATED mode and even though they do not own it.
     *
     * This is the single use of the read-visibility hook in the application,
     * and the reasoning is worth keeping next to it: a household that has
     * agreed to divide a bill has, by agreeing, made that bill the business of
     * everybody named in it. Hiding it from them would leave a member with a
     * share of a cost they are not allowed to look at.
     *
     * Three things this does *not* do, each load-bearing:
     *
     *  - it does not cross a household — the household predicate is AND-ed
     *    outside this clause by readPredicate() and stays absolute;
     *  - it does not grant any write — the base class consults this hook from
     *    scopedWhere() only, so UPDATE, DELETE and the in-scope assertion still
     *    use the unwidened predicate;
     *  - it does not apply in SHARED mode, where the owner clause is absent
     *    and everybody in the household can see everything anyway.
     *
     * @param array<string, mixed> $params
     */
    protected function readVisibilityPredicate(Scope $scope, array &$params): string
    {
        $params['__participant'] = $scope->userId;

        return 'EXISTS (SELECT 1 FROM ' . $this->quote('subscription_splits') . ' split'
            . ' WHERE split.' . $this->quote('subscription_id') . ' = ' . $this->qualify('id')
            . ' AND split.' . $this->quote('user_id') . ' = :__participant)';
    }

    protected function filterableColumns(): array
    {
        return [
            'id',
            'household_id',
            'owner_user_id',
            'payer_user_id',
            'name',
            'notes',
            'price_minor',
            'currency',
            'subscription_type',
            'billing_cycle',
            'next_payment_date',
            'is_trial',
            'trial_end_date',
            'split_mode',
            'usage_count',
            'usage_rating',
            'is_active',
            'category_id',
            'created_at',
        ];
    }

    /**
     * @return list<Subscription>
     */
    public function findForList(Scope $scope, SubscriptionFilter $filter): array
    {
        $criteria = $this->criteriaFor($filter)
            ->orderBy(self::SORT_COLUMNS[$filter->sort], $filter->direction)
            ->paginate($filter->page, $filter->perPage);

        // A stable secondary sort keeps pagination deterministic when the
        // primary column has ties or NULLs.
        $criteria = $criteria->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->tagFilterClause($filter, $params)
            . $this->compileOrderBy($criteria)
            . $this->compileLimit($criteria, $params);

        $rows = $this->db->fetchAll($sql, $params);

        return $this->hydrateAll($scope, $rows);
    }

    public function countForList(Scope $scope, SubscriptionFilter $filter): int
    {
        $params = [];
        $sql = 'SELECT COUNT(*) FROM ' . $this->quote('subscriptions')
            . $this->scopedWhere($scope, $this->criteriaFor($filter), $params)
            . $this->tagFilterClause($filter, $params);

        return (int) $this->db->fetchValue($sql, $params);
    }

    public function find(Scope $scope, int $id): ?Subscription
    {
        $criteria = Criteria::new()->equals('id', $id);
        $params = [];
        $sql = $this->selectWithJoins() . $this->scopedWhere($scope, $criteria, $params) . ' LIMIT 1';

        $rows = $this->db->fetchAll($sql, $params);
        if ($rows === []) {
            return null;
        }

        return $this->hydrateAll($scope, $rows)[0];
    }

    /**
     * Every in-scope row, for statistics. Deliberately unpaginated: monthly
     * normalisation is integer arithmetic done in PHP, not in SQL, so that the
     * two engines cannot disagree about rounding.
     *
     * @return list<Subscription>
     */
    public function findAllForStats(Scope $scope, bool $activeOnly = true): array
    {
        $criteria = Criteria::new();
        if ($activeOnly) {
            $criteria = $criteria->equals('is_active', true);
        }

        $params = [];
        $sql = $this->selectWithJoins() . $this->scopedWhere($scope, $criteria, $params);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Trials ending within the given window, soonest first.
     *
     * Surfaced prominently because this is the deadline people actually lose
     * money to: a trial that converts unnoticed is the whole reason for
     * tracking them.
     *
     * @return list<Subscription>
     */
    public function findTrialsEnding(Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->equals('is_trial', true)
            ->where('trial_end_date', '>=', $from->format('Y-m-d'))
            ->where('trial_end_date', '<=', $to->format('Y-m-d'))
            ->orderBy('trial_end_date', 'asc');

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Trials whose end date has passed and which therefore need converting.
     *
     * Restricted to rows this scope may write, not merely read. The conversion
     * that follows is an UPDATE, and handing back a row the caller is about to
     * be refused would turn a page view into an error — see writableWhere().
     *
     * @return list<Subscription>
     */
    public function findTrialsToConvert(Scope $scope, DateTimeImmutable $today): array
    {
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->equals('is_trial', true)
            ->where('trial_end_date', '<', $today->format('Y-m-d'))
            ->orderBy('trial_end_date', 'asc');

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->writableWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Turn a trial into the paid subscription it became.
     *
     * The price itself is not set here: that is a price-history row, written by
     * PriceHistoryService in the same transaction, so that the conversion shows
     * up in the trend as the step it is.
     */
    public function convertTrial(
        Scope $scope,
        int $id,
        ?BillingCycle $cycle,
        ?int $cycleDays,
        DateTimeImmutable $nextPaymentDate,
    ): void {
        $this->updateScoped($scope, $id, [
            'is_trial' => false,
            'converts_to_price_minor' => null,
            'converts_to_billing_cycle' => null,
            'converts_to_cycle_days' => null,
            'billing_cycle' => $cycle?->value,
            'cycle_days' => $cycleDays,
            'next_payment_date' => $nextPaymentDate->format('Y-m-d'),
            'anchor_day' => (int) $nextPaymentDate->format('j'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Active subscriptions whose next payment falls within the given window.
     *
     * @return list<Subscription>
     */
    public function findUpcoming(Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->where('next_payment_date', '>=', $from->format('Y-m-d'))
            ->where('next_payment_date', '<=', $to->format('Y-m-d'))
            ->orderBy('next_payment_date', 'asc');

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Active recurring subscriptions whose next payment date is in the past,
     * for the cycle auto-advance.
     *
     * Write-scoped for the same reason as findTrialsToConvert: every row
     * returned here is about to be updated.
     *
     * @return list<Subscription>
     */
    public function findOverdue(Scope $scope, DateTimeImmutable $today): array
    {
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->equals('subscription_type', SubscriptionType::Recurring->value)
            ->where('next_payment_date', '<', $today->format('Y-m-d'));

        $params = [];
        $sql = $this->selectWithJoins() . $this->writableWhere($scope, $criteria, $params);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int>            $tagIds
     */
    public function create(Scope $scope, array $data, array $tagIds): int
    {
        return $this->db->transactional(function () use ($scope, $data, $tagIds): int {
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $data['created_at'] = $now;
            $data['updated_at'] = $now;

            $id = $this->insertScoped($scope, $data);
            $this->syncTags($scope, $id, $tagIds);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int>            $tagIds
     */
    public function update(Scope $scope, int $id, array $data, array $tagIds): void
    {
        $this->db->transactional(function () use ($scope, $id, $data, $tagIds): void {
            $data['updated_at'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->updateScoped($scope, $id, $data);
            $this->syncTags($scope, $id, $tagIds);
        });
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->db->transactional(function () use ($scope, $id): void {
            // The join rows are removed through the scoped subscription, so a
            // caller cannot detach tags from somebody else's row.
            $this->assertInScope($scope, $id);
            $this->db->execute(
                'DELETE FROM ' . $this->quote('subscription_tags')
                . ' WHERE ' . $this->quote('subscription_id') . ' = :id',
                ['id' => $id],
            );
            $this->deleteScoped($scope, $id);
        });
    }

    /**
     * Move a subscription on to its next billing date.
     */
    public function setNextPaymentDate(Scope $scope, int $id, DateTimeImmutable $date): void
    {
        $this->updateScoped($scope, $id, [
            'next_payment_date' => $date->format('Y-m-d'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Record one use.
     *
     * The increment is done in SQL rather than read-modify-write so that two
     * people tapping "used it" at once cannot lose a count between them.
     * `usage_counted_since` is set on the first use so that the count always
     * has a period to be judged against.
     */
    public function incrementUsage(Scope $scope, int $id, DateTimeImmutable $today): void
    {
        $params = [
            '__id' => $id,
            'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'today' => $today->format('Y-m-d'),
        ];

        $sql = 'UPDATE ' . $this->quote('subscriptions')
            . ' SET ' . $this->quote('usage_count') . ' = ' . $this->quote('usage_count') . ' + 1,'
            . ' ' . $this->quote('usage_counted_since') . ' = COALESCE('
            . $this->quote('usage_counted_since') . ', :today),'
            . ' ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :__id'
            . ' AND ' . $this->scopePredicateUnqualified($scope, $params);

        if ($this->db->execute($sql, $params) < 1) {
            throw \App\Security\ScopeViolationException::forRow($this->table(), $id);
        }
    }

    public function setUsageRating(Scope $scope, int $id, ?int $rating): void
    {
        $this->updateScoped($scope, $id, [
            'usage_rating' => $rating,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function resetUsage(Scope $scope, int $id, DateTimeImmutable $today): void
    {
        $this->updateScoped($scope, $id, [
            'usage_count' => 0,
            'usage_counted_since' => $today->format('Y-m-d'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Apply one field to many subscriptions at once, each write individually
     * scoped.
     *
     * There is no bulk UPDATE ... WHERE id IN (...) here on purpose. Writing
     * them one at a time means each carries the scope predicate and each
     * reports whether it matched, so a bulk action cannot quietly modify a row
     * the caller could not have modified individually.
     *
     * @param list<int>            $ids
     * @param array<string, mixed> $data
     * @return int Number of rows actually changed.
     */
    public function updateMany(Scope $scope, array $ids, array $data): int
    {
        if ($ids === [] || $data === []) {
            return 0;
        }

        return $this->db->transactional(function () use ($scope, $ids, $data): int {
            $changed = 0;
            foreach (array_unique($ids) as $id) {
                try {
                    $this->updateScoped($scope, $id, $data + [
                        'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                    $changed++;
                } catch (\App\Security\ScopeViolationException) {
                    // A row that is not this caller's to change is skipped
                    // rather than failing the whole action: a bulk edit run
                    // over a stale selection should do what it can and report
                    // the count, not abort on the one row somebody else has
                    // since deleted.
                    continue;
                }
            }

            return $changed;
        });
    }

    /**
     * Change how a subscription's cost is divided.
     */
    public function setSplitMode(Scope $scope, int $id, SplitMode $mode): void
    {
        $this->updateScoped($scope, $id, [
            'split_mode' => $mode->value,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Update the denormalised current price.
     *
     * `subscriptions.price_minor` is a cache of "the latest price-history row
     * that has taken effect". It exists because the list view sorts, filters
     * and totals on it, and a correlated sub-query on every row would be a poor
     * trade. The invariant that keeps the two honest is that this method is
     * only ever called from PriceHistoryService, inside the same transaction as
     * the history row it reflects.
     */
    public function setPrice(Scope $scope, int $id, Money $price): void
    {
        $this->updateScoped($scope, $id, [
            'price_minor' => $price->amountMinor,
            'currency' => $price->currency,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Distinct currencies in scope, for the filter pick-list.
     *
     * @return list<string>
     */
    public function distinctCurrencies(Scope $scope): array
    {
        $params = [];
        $sql = 'SELECT DISTINCT ' . $this->qualify('currency') . ' AS currency FROM ' . $this->quote('subscriptions')
            . $this->scopedWhere($scope, Criteria::new(), $params)
            . ' ORDER BY ' . $this->quote('currency') . ' ASC';

        return array_values(array_map(
            static fn (array $row): string => (string) $row['currency'],
            $this->db->fetchAll($sql, $params),
        ));
    }

    /**
     * Guard for the operations that modify a subscription's associations.
     *
     * It asks the *write* predicate, not the read one. Since shared-cost splits
     * let a participant see a subscription they do not own, a read-based check
     * here would let that participant rewrite its tags — the tag sync deletes
     * and re-inserts join rows directly, so this assertion is the only thing
     * standing in front of it.
     *
     * @throws \App\Security\ScopeViolationException
     */
    /**
     * The subscription, but only if the caller may write to it.
     *
     * find() is widened for split participants, so a service that reads with
     * find() and then writes has already lost the distinction. Anything that
     * needs the row *in order to change something* asks for it here instead.
     */
    public function findForWrite(Scope $scope, int $id): ?Subscription
    {
        $criteria = Criteria::new()->equals('id', $id);

        $params = [];
        $sql = $this->selectWithJoins() . $this->writableWhere($scope, $criteria, $params);

        $row = $this->db->fetchOne($sql, $params);

        return $row === null ? null : $this->hydrateAll($scope, [$row])[0];
    }

    private function assertInScope(Scope $scope, int $id): void
    {
        if (!$this->existsForWrite($scope, $id)) {
            throw \App\Security\ScopeViolationException::forRow($this->table(), $id);
        }
    }

    private function criteriaFor(SubscriptionFilter $filter): Criteria
    {
        $criteria = Criteria::new();

        if (!$filter->includeInactive) {
            $criteria = $criteria->equals('is_active', true);
        }
        if ($filter->search !== '') {
            $criteria = $criteria->search(['name', 'notes'], $filter->search);
        }
        if ($filter->categoryId !== null) {
            $criteria = $criteria->equals('category_id', $filter->categoryId);
        }
        if ($filter->ownerUserId !== null) {
            $criteria = $criteria->equals('owner_user_id', $filter->ownerUserId);
        }
        if ($filter->currency !== null) {
            $criteria = $criteria->equals('currency', $filter->currency);
        }
        if ($filter->type !== null) {
            $criteria = $criteria->equals('subscription_type', $filter->type->value);
        }

        return $criteria;
    }

    /**
     * Tag filtering is an EXISTS sub-query rather than a join so that matching
     * several tags does not multiply the result rows.
     *
     * @param array<string, mixed> $params
     */
    private function tagFilterClause(SubscriptionFilter $filter, array &$params): string
    {
        if ($filter->tagIds === []) {
            return '';
        }

        $placeholders = [];
        foreach (array_values($filter->tagIds) as $index => $tagId) {
            $name = 'tag_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $tagId;
        }

        return ' AND EXISTS (SELECT 1 FROM ' . $this->quote('subscription_tags') . ' st'
            . ' WHERE st.' . $this->quote('subscription_id') . ' = ' . $this->qualify('id')
            . ' AND st.' . $this->quote('tag_id') . ' IN (' . implode(', ', $placeholders) . '))';
    }

    private function selectWithJoins(): string
    {
        $subscriptions = $this->quote('subscriptions');

        return 'SELECT ' . $subscriptions . '.*,'
            . ' c.' . $this->quote('name') . ' AS category_name,'
            . ' owner_user.' . $this->quote('display_name') . ' AS owner_name,'
            . ' payer_user.' . $this->quote('display_name') . ' AS payer_name'
            . ' FROM ' . $subscriptions
            . ' LEFT JOIN ' . $this->quote('categories') . ' c'
            . ' ON c.' . $this->quote('id') . ' = ' . $this->qualify('category_id')
            . ' LEFT JOIN ' . $this->quote('users') . ' owner_user'
            . ' ON owner_user.' . $this->quote('id') . ' = ' . $this->qualify('owner_user_id')
            . ' LEFT JOIN ' . $this->quote('users') . ' payer_user'
            . ' ON payer_user.' . $this->quote('id') . ' = ' . $this->qualify('payer_user_id');
    }

    /**
     * @param list<int> $tagIds
     */
    private function syncTags(Scope $scope, int $subscriptionId, array $tagIds): void
    {
        $this->assertInScope($scope, $subscriptionId);

        $this->db->execute(
            'DELETE FROM ' . $this->quote('subscription_tags')
            . ' WHERE ' . $this->quote('subscription_id') . ' = :id',
            ['id' => $subscriptionId],
        );

        foreach (array_unique($tagIds) as $tagId) {
            $this->db->execute(
                'INSERT INTO ' . $this->quote('subscription_tags')
                . ' (' . $this->quote('subscription_id') . ', ' . $this->quote('tag_id') . ')'
                . ' VALUES (:subscription, :tag)',
                ['subscription' => $subscriptionId, 'tag' => $tagId],
            );
        }
    }

    /**
     * Load the tags for a set of subscriptions in one query, re-applying the
     * scope predicate rather than trusting the ids it was given.
     *
     * @param list<int> $subscriptionIds
     * @return array<int, list<Tag>>
     */
    private function tagsFor(Scope $scope, array $subscriptionIds): array
    {
        if ($subscriptionIds === []) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach (array_values($subscriptionIds) as $index => $id) {
            $name = 'sub_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }

        $sql = 'SELECT st.' . $this->quote('subscription_id') . ' AS subscription_id,'
            . ' t.' . $this->quote('id') . ' AS id,'
            . ' t.' . $this->quote('household_id') . ' AS household_id,'
            . ' t.' . $this->quote('name') . ' AS name'
            . ' FROM ' . $this->quote('subscription_tags') . ' st'
            . ' INNER JOIN ' . $this->quote('tags') . ' t'
            . ' ON t.' . $this->quote('id') . ' = st.' . $this->quote('tag_id')
            . ' INNER JOIN ' . $this->quote('subscriptions')
            . ' ON ' . $this->qualify('id') . ' = st.' . $this->quote('subscription_id')
            . ' WHERE st.' . $this->quote('subscription_id') . ' IN (' . implode(', ', $placeholders) . ')'
            // The read predicate, deliberately, and the one place in this class
            // where that choice is easy to get wrong. This runs on every read
            // path to decorate rows that have *already* been found by the read
            // predicate; narrowing it here would hand a split participant a
            // subscription stripped of its tags, silently, with no error — the
            // same row shown differently to two people looking at it.
            . ' AND ' . $this->readPredicate($scope, $params)
            . ' ORDER BY t.' . $this->quote('name') . ' ASC';

        $grouped = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $grouped[(int) $row['subscription_id']][] = new Tag(
                id: (int) $row['id'],
                householdId: (int) $row['household_id'],
                name: (string) $row['name'],
            );
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Subscription>
     */
    private function hydrateAll(Scope $scope, array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $tags = $this->tagsFor($scope, $ids);

        return array_map(
            fn (array $row): Subscription => $this->hydrate($row, $tags[(int) $row['id']] ?? []),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Tag>            $tags
     */
    private function hydrate(array $row, array $tags): Subscription
    {
        $type = SubscriptionType::from((string) $row['subscription_type']);
        $cycle = BillingCycle::tryFromString(
            isset($row['billing_cycle']) ? (string) $row['billing_cycle'] : null,
        );

        return new Subscription(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            ownerUserId: (int) $row['owner_user_id'],
            payerUserId: $this->nullableInt($row['payer_user_id'] ?? null),
            name: (string) $row['name'],
            notes: $this->nullableString($row['notes'] ?? null),
            price: Money::of((int) $row['price_minor'], (string) $row['currency']),
            type: $type,
            billingCycle: $cycle,
            cycleDays: $this->nullableInt($row['cycle_days'] ?? null),
            nextPaymentDate: $this->nullableDate($row['next_payment_date'] ?? null),
            startDate: $this->nullableDate($row['start_date'] ?? null),
            anchorDay: $this->nullableInt($row['anchor_day'] ?? null),
            noticePeriod: NoticePeriod::of(
                $this->nullableInt($row['notice_period_amount'] ?? null),
                $this->nullableString($row['notice_period_unit'] ?? null),
            ),
            isTrial: $this->db->platform()->toBoolean($row['is_trial'] ?? false),
            trialEndDate: $this->nullableDate($row['trial_end_date'] ?? null),
            convertsToPrice: isset($row['converts_to_price_minor'])
                ? Money::of((int) $row['converts_to_price_minor'], (string) $row['currency'])
                : null,
            convertsToBillingCycle: BillingCycle::tryFromString(
                $this->nullableString($row['converts_to_billing_cycle'] ?? null),
            ),
            convertsToCycleDays: $this->nullableInt($row['converts_to_cycle_days'] ?? null),
            splitMode: SplitMode::tryFromString($this->nullableString($row['split_mode'] ?? null))
                ?? SplitMode::None,
            usageCount: (int) ($row['usage_count'] ?? 0),
            usageRating: $this->nullableInt($row['usage_rating'] ?? null),
            usageCountedSince: $this->nullableDate($row['usage_counted_since'] ?? null),
            isActive: $this->db->platform()->toBoolean($row['is_active']),
            logoPath: $this->nullableString($row['logo_path'] ?? null),
            categoryId: $this->nullableInt($row['category_id'] ?? null),
            categoryName: $this->nullableString($row['category_name'] ?? null),
            ownerName: $this->nullableString($row['owner_name'] ?? null),
            payerName: $this->nullableString($row['payer_name'] ?? null),
            tags: $tags,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
