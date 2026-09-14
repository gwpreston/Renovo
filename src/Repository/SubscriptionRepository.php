<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BillingCycle;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\Tag;
use App\Domain\Money;
use App\Domain\NoticePeriod;
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
     * @return list<Subscription>
     */
    public function findOverdue(Scope $scope, DateTimeImmutable $today): array
    {
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->equals('subscription_type', SubscriptionType::Recurring->value)
            ->where('next_payment_date', '<', $today->format('Y-m-d'));

        $params = [];
        $sql = $this->selectWithJoins() . $this->scopedWhere($scope, $criteria, $params);

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
     * @throws \App\Security\ScopeViolationException
     */
    private function assertInScope(Scope $scope, int $id): void
    {
        if ($this->findOneScoped($scope, Criteria::new()->equals('id', $id)) === null) {
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
            . ' AND ' . $this->scopePredicate($scope, $params)
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
