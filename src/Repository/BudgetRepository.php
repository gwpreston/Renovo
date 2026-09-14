<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BudgetPeriod;
use App\Domain\Entity\Budget;
use App\Domain\Money;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Budgets, scoped like any other household data.
 *
 * No read-visibility widening here: a budget is a personal target, not a shared
 * bill. In ISOLATED mode a member sees only their own, which is the ordinary
 * rule and exactly what the mode is for.
 */
final class BudgetRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'budgets';
    }

    protected function filterableColumns(): array
    {
        return [
            'id',
            'household_id',
            'owner_user_id',
            'name',
            'category_id',
            'period',
            'amount_minor',
            'currency',
            'is_active',
        ];
    }

    /**
     * @return list<Budget>
     */
    public function findAll(Scope $scope, bool $activeOnly = true): array
    {
        $criteria = Criteria::new()
            ->orderBy('name', 'asc')
            ->orderBy('id', 'asc');

        if ($activeOnly) {
            $criteria = $criteria->equals('is_active', true);
        }

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    public function find(Scope $scope, int $id): ?Budget
    {
        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, Criteria::new()->equals('id', $id), $params)
            . ' LIMIT 1';

        $row = $this->db->fetchOne($sql, $params);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(Scope $scope, array $data): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->insertScoped($scope, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Scope $scope, int $id, array $data): void
    {
        $data['updated_at'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->updateScoped($scope, $id, $data);
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->deleteScoped($scope, $id);
    }

    private function selectWithJoins(): string
    {
        $budgets = $this->quote('budgets');

        return 'SELECT ' . $budgets . '.*,'
            . ' c.' . $this->quote('name') . ' AS category_name,'
            . ' owner_user.' . $this->quote('display_name') . ' AS owner_name'
            . ' FROM ' . $budgets
            . ' LEFT JOIN ' . $this->quote('categories') . ' c'
            . ' ON c.' . $this->quote('id') . ' = ' . $this->qualify('category_id')
            . ' LEFT JOIN ' . $this->quote('users') . ' owner_user'
            . ' ON owner_user.' . $this->quote('id') . ' = ' . $this->qualify('owner_user_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Budget
    {
        $threshold = $row['warn_threshold_percent'] ?? null;

        return new Budget(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            ownerUserId: (int) $row['owner_user_id'],
            name: (string) $row['name'],
            categoryId: ($row['category_id'] ?? null) === null ? null : (int) $row['category_id'],
            categoryName: ($row['category_name'] ?? null) === null ? null : (string) $row['category_name'],
            period: BudgetPeriod::tryFrom((string) $row['period']) ?? BudgetPeriod::Monthly,
            amount: Money::of((int) $row['amount_minor'], (string) $row['currency']),
            warnThresholdPercent: $threshold === null ? null : (int) $threshold,
            isActive: $this->db->platform()->toBoolean($row['is_active']),
            ownerName: ($row['owner_name'] ?? null) === null ? null : (string) $row['owner_name'],
        );
    }
}
