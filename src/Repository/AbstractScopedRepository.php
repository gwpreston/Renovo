<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Criteria;
use App\Security\Scope;
use App\Security\ScopeViolationException;

/**
 * The single gateway to household-owned data.
 *
 * Every method that reads or writes takes a Scope as a required argument, and
 * every statement this class emits has the scope predicate welded into its
 * WHERE clause before it reaches the database. There is no method — public,
 * protected or otherwise — that produces a statement without it, so a route or
 * service cannot bypass isolation by forgetting a condition. It has to go
 * through here, and going through here applies the rules.
 *
 * The predicate is:
 *
 *   household_id = :scope   AND (when the instance is ISOLATED and the table
 *                                has an owner) owner_user_id = :scope_user
 *
 * Two properties are worth stating explicitly because both are easy to
 * implement backwards:
 *
 *  - Writes are scoped exactly like reads. A scoped UPDATE or DELETE carries
 *    the same predicate and asserts that it affected a row; if it did not, the
 *    row either does not exist or belongs to somebody else, and the call
 *    raises ScopeViolationException rather than quietly succeeding.
 *  - The instance-admin flag contributes nothing. An admin with no membership
 *    of a household sees none of its data, by construction: their scope has no
 *    household id and the predicate degrades to a false condition.
 */
abstract class AbstractScopedRepository extends AbstractRepository
{
    private const SCOPE_HOUSEHOLD_PARAM = '__scope_household';
    private const SCOPE_OWNER_PARAM = '__scope_owner';

    /**
     * The column holding the household a row belongs to.
     */
    protected function householdColumn(): string
    {
        return 'household_id';
    }

    /**
     * The column holding the individual owner, or null for tables that are
     * household-wide metadata rather than personal data (categories, tags).
     * Such tables stay visible to the whole household even in ISOLATED mode:
     * they carry no financial information, and hiding them would make another
     * member's category names leak through a "missing" id instead.
     */
    protected function ownerColumn(): ?string
    {
        return 'owner_user_id';
    }

    /**
     * Build the mandatory scope predicate.
     *
     * @param array<string, mixed> $params
     */
    final protected function scopePredicate(Scope $scope, array &$params): string
    {
        if (!$scope->hasHousehold()) {
            // No household membership: no rows. This is the path an instance
            // admin takes, and it is deliberate.
            return '1 = 0';
        }

        $params[self::SCOPE_HOUSEHOLD_PARAM] = $scope->householdId;
        $predicate = $this->qualify($this->householdColumn()) . ' = :' . self::SCOPE_HOUSEHOLD_PARAM;

        $ownerColumn = $this->ownerColumn();
        if ($ownerColumn !== null && $scope->isOwnerRestricted()) {
            $params[self::SCOPE_OWNER_PARAM] = $scope->userId;
            $predicate .= ' AND ' . $this->qualify($ownerColumn) . ' = :' . self::SCOPE_OWNER_PARAM;
        }

        return $predicate;
    }

    /**
     * @param array<string, mixed> $params
     */
    final protected function scopedWhere(Scope $scope, Criteria $criteria, array &$params): string
    {
        $conditions = array_merge(
            [$this->scopePredicate($scope, $params)],
            $this->compileConditions($criteria, $params),
        );

        return ' WHERE ' . implode(' AND ', $conditions);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function findAllScoped(Scope $scope, Criteria $criteria, string $defaultOrder = ''): array
    {
        $params = [];
        $sql = 'SELECT ' . $this->quote($this->table()) . '.* FROM ' . $this->quote($this->table())
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria, $defaultOrder)
            . $this->compileLimit($criteria, $params);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findOneScoped(Scope $scope, Criteria $criteria): ?array
    {
        $params = [];
        $sql = 'SELECT ' . $this->quote($this->table()) . '.* FROM ' . $this->quote($this->table())
            . $this->scopedWhere($scope, $criteria, $params)
            . ' LIMIT 1';

        return $this->db->fetchOne($sql, $params);
    }

    protected function countScoped(Scope $scope, Criteria $criteria): int
    {
        $params = [];
        $sql = 'SELECT COUNT(*) FROM ' . $this->quote($this->table())
            . $this->scopedWhere($scope, $criteria, $params);

        return (int) $this->db->fetchValue($sql, $params);
    }

    /**
     * Insert a row, forcing the scope's household and owner onto it.
     *
     * The caller cannot choose a different household: the scope columns are
     * overwritten after the caller's data, not merged before it.
     *
     * @param array<string, mixed> $data
     */
    protected function insertScoped(Scope $scope, array $data): int
    {
        if (!$scope->hasHousehold()) {
            throw new ScopeViolationException('Cannot create a record without a household.');
        }

        $data[$this->householdColumn()] = $scope->householdId;

        $ownerColumn = $this->ownerColumn();
        if ($ownerColumn !== null && !array_key_exists($ownerColumn, $data)) {
            $data[$ownerColumn] = $scope->userId;
        }

        // In ISOLATED mode a user may only create rows they themselves own,
        // otherwise they could create a row they would then be unable to see.
        if ($ownerColumn !== null && $scope->isOwnerRestricted()) {
            $data[$ownerColumn] = $scope->userId;
        }

        return $this->db->insert($this->table(), $data);
    }

    /**
     * Update one row by id, within scope.
     *
     * @param array<string, mixed> $data
     * @throws ScopeViolationException when the row is absent or out of scope.
     */
    protected function updateScoped(Scope $scope, int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        // The scope columns are not writable through an update.
        unset($data[$this->householdColumn()]);
        $ownerColumn = $this->ownerColumn();
        if ($ownerColumn !== null && $scope->isOwnerRestricted()) {
            unset($data[$ownerColumn]);
        }

        $params = [];
        $assignments = [];
        foreach ($data as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        $params['__id'] = $id;
        $sql = 'UPDATE ' . $this->quote($this->table())
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $this->quote($this->table()) . '.' . $this->quote('id') . ' = :__id'
            . ' AND ' . $this->scopePredicate($scope, $params);

        if ($this->db->execute($sql, $params) < 1) {
            throw ScopeViolationException::forRow($this->table(), $id);
        }
    }

    /**
     * Delete one row by id, within scope.
     *
     * @throws ScopeViolationException when the row is absent or out of scope.
     */
    protected function deleteScoped(Scope $scope, int $id): void
    {
        $params = ['__id' => $id];
        $sql = 'DELETE FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('id') . ' = :__id'
            . ' AND ' . $this->scopePredicateUnqualified($scope, $params);

        if ($this->db->execute($sql, $params) < 1) {
            throw ScopeViolationException::forRow($this->table(), $id);
        }
    }

    /**
     * DELETE cannot use a table-qualified column on MySQL in the same way as
     * SELECT, so the predicate is emitted with bare column names here. It is
     * otherwise identical, and still mandatory.
     *
     * @param array<string, mixed> $params
     */
    final protected function scopePredicateUnqualified(Scope $scope, array &$params): string
    {
        if (!$scope->hasHousehold()) {
            return '1 = 0';
        }

        $params[self::SCOPE_HOUSEHOLD_PARAM] = $scope->householdId;
        $predicate = $this->quote($this->householdColumn()) . ' = :' . self::SCOPE_HOUSEHOLD_PARAM;

        $ownerColumn = $this->ownerColumn();
        if ($ownerColumn !== null && $scope->isOwnerRestricted()) {
            $params[self::SCOPE_OWNER_PARAM] = $scope->userId;
            $predicate .= ' AND ' . $this->quote($ownerColumn) . ' = :' . self::SCOPE_OWNER_PARAM;
        }

        return $predicate;
    }
}
