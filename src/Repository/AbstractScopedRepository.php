<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Visibility;
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
 * There are in fact two predicates, and the difference between them is the
 * subtlest thing in this class:
 *
 *  - `scopePredicate()` is the **write** predicate, used by updateScoped,
 *    deleteScoped and existsForWrite. It is the rule above, exactly.
 *  - `readPredicate()` is the **read** predicate, used by scopedWhere and
 *    therefore by every SELECT. It is the same rule, optionally widened by
 *    `readVisibilityPredicate()` for rows a user is entitled to see without
 *    owning — a subscription they pay a share of, and nothing else so far.
 *
 * Keeping them separate is what stops "can see" from quietly becoming "can
 * change". If the widening lived in one shared predicate, a member listed on a
 * shared-cost split would gain UPDATE and DELETE on somebody else's
 * subscription the moment the feature shipped, and no existing test would
 * notice.
 *
 * Two further properties are worth stating explicitly because both are easy to
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
    private const PRIVACY_VIEWER_PARAM = '__private_viewer';

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
        return $this->householdAndOwner($scope, $params, $scope->restrictsWritesToOwner(), true);
    }

    /**
     * The household clause, with the owner clause when the caller asks for it.
     *
     * The one place either predicate is built, and it takes the restriction as
     * an argument rather than asking the scope itself. That is the whole point:
     * reads and writes are restricted by different questions — an ISOLATED
     * instance narrows both, a Contributor's role narrows only writes — and a
     * helper that asked would have to pick one of them and be wrong for the
     * other. The read predicate used to be defined as "the write predicate,
     * optionally widened", which was true while one flag answered for both and
     * would now hand a Contributor an owner clause on every SELECT.
     *
     * @param array<string, mixed> $params
     * @param bool $restrictToOwner Whether to confine the rows to this user's own.
     * @param bool $qualified       Whether columns carry the table alias.
     */
    private function householdAndOwner(
        Scope $scope,
        array &$params,
        bool $restrictToOwner,
        bool $qualified,
    ): string {
        if (!$scope->hasHousehold()) {
            // No household membership: no rows. This is the path an instance
            // admin takes, and it is deliberate.
            return '1 = 0';
        }

        $column = fn (string $name): string => $qualified ? $this->qualify($name) : $this->quote($name);

        $params[self::SCOPE_HOUSEHOLD_PARAM] = $scope->householdId;
        $predicate = $column($this->householdColumn()) . ' = :' . self::SCOPE_HOUSEHOLD_PARAM;

        $ownerColumn = $this->ownerColumn();
        if ($ownerColumn !== null && $restrictToOwner) {
            $params[self::SCOPE_OWNER_PARAM] = $scope->userId;
            $predicate .= ' AND ' . $column($ownerColumn) . ' = :' . self::SCOPE_OWNER_PARAM;
        }

        return $predicate . $this->privacyClause($scope, $params, $qualified);
    }

    /**
     * A condition that hides rows from everybody but one member, AND-ed onto
     * every predicate this class builds — read, write and unqualified alike.
     *
     * The one use is a subscription marked "only me": invisible to the rest of
     * the household in either isolation mode, Owner/Admins included. It is the
     * opposite of `readVisibilityPredicate()` in every respect that matters:
     *
     *  - it narrows rather than widens, so it is AND-ed, never OR-ed;
     *  - it applies in SHARED mode as well as ISOLATED, which is why it lives
     *    in the helper both modes pass through rather than in the widened
     *    branch of `readPredicate()` that only ISOLATED reaches;
     *  - it applies to writes too — a row somebody cannot see is not one they
     *    may pause, bulk-edit or delete by guessing its id;
     *  - in ISOLATED mode it is applied *after* the widening, outside the OR
     *    group, so no split participation can re-expose a private row.
     *
     * Returning null — the default — means no such rows.
     *
     * @param string $viewerParam The placeholder holding the viewer's id,
     *                            already bound by the caller.
     * @param bool   $qualified   Whether columns carry the table alias.
     */
    protected function privacyPredicate(string $viewerParam, bool $qualified): ?string
    {
        return null;
    }

    /**
     * The privacy predicate for a table whose rows belong to a subscription:
     * hidden whenever the parent is private to somebody else.
     *
     * Price-history and attachment rows carry a copy of the owner but not of
     * the visibility, so they ask the parent. `NOT EXISTS` rather than a join,
     * so the clause can be AND-ed into any statement, a DELETE included.
     */
    final protected function privateParentPredicate(string $viewerParam, bool $qualified): string
    {
        $foreign = $qualified ? $this->qualify('subscription_id') : $this->quote('subscription_id');

        return 'NOT EXISTS (SELECT 1 FROM ' . $this->quote('subscriptions') . ' private_parent'
            . ' WHERE private_parent.' . $this->quote('id') . ' = ' . $foreign
            . ' AND private_parent.' . $this->quote('visibility') . " = '" . Visibility::Payer->value . "'"
            . ' AND private_parent.' . $this->quote('owner_user_id') . ' <> :' . $viewerParam . ')';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function privacyClause(Scope $scope, array &$params, bool $qualified): string
    {
        $predicate = $this->privacyPredicate(self::PRIVACY_VIEWER_PARAM, $qualified);
        if ($predicate === null) {
            return '';
        }

        $params[self::PRIVACY_VIEWER_PARAM] = $scope->userId;

        return ' AND ' . $predicate;
    }

    /**
     * An extra condition that widens what a scope may *read*, OR-ed with the
     * owner clause.
     *
     * The one use of it is shared-cost splitting: a member who is a participant
     * in a split must be able to see the subscription they are paying part of,
     * even when the instance is ISOLATED and they do not own it. Returning null
     * — the default — means no widening at all.
     *
     * Two constraints on any implementation, both load-bearing:
     *
     *  - It never escapes the household. It is OR-ed with the owner clause
     *    only; `household_id = :scope` is AND-ed outside it and stays
     *    absolute.
     *  - It is read-only, by construction rather than by convention. This hook
     *    is consulted by `scopedWhere()`, which serves the SELECT methods and
     *    nothing else. `scopePredicate()` — which is what UPDATE, DELETE and
     *    the in-scope assertion use — does not consult it. Being able to see a
     *    subscription you contribute to is not the same as being able to
     *    change it, and the split between the two predicates is what makes
     *    that structural instead of something each call site has to remember.
     *
     * @param array<string, mixed> $params
     */
    protected function readVisibilityPredicate(Scope $scope, array &$params): ?string
    {
        return null;
    }

    /**
     * The predicate for reads: the household, narrowed by the *isolation mode*
     * alone and then optionally widened.
     *
     * Deliberately not "the write predicate, optionally widened". A role may
     * confine what somebody changes without confining what they see, so the two
     * predicates are built side by side from the same helper rather than one
     * from the other.
     *
     * @param array<string, mixed> $params
     */
    final protected function readPredicate(Scope $scope, array &$params): string
    {
        if (!$scope->hasHousehold()) {
            return '1 = 0';
        }

        $restricted = $scope->restrictsReadsToOwner();

        $ownerColumn = $this->ownerColumn();
        if ($ownerColumn === null || !$restricted) {
            // With no owner column, or in SHARED mode, the household predicate
            // is already the whole answer and there is nothing to widen. Built
            // here rather than borrowed from the write predicate, which may be
            // narrower than this one — a Contributor reads the household and
            // writes only their own.
            return $this->householdAndOwner($scope, $params, $restricted, true);
        }

        $extra = $this->readVisibilityPredicate($scope, $params);
        if ($extra === null) {
            return $this->householdAndOwner($scope, $params, $restricted, true);
        }

        $params[self::SCOPE_HOUSEHOLD_PARAM] = $scope->householdId;
        $params[self::SCOPE_OWNER_PARAM] = $scope->userId;

        // The privacy clause goes after the OR group, never inside it: a split
        // participation may widen what somebody sees, and must not widen it to
        // a row its payer has kept to themselves.
        return $this->qualify($this->householdColumn()) . ' = :' . self::SCOPE_HOUSEHOLD_PARAM
            . ' AND (' . $this->qualify($ownerColumn) . ' = :' . self::SCOPE_OWNER_PARAM
            . ' OR ' . $extra . ')'
            . $this->privacyClause($scope, $params, true);
    }

    /**
     * A WHERE clause restricted to rows this scope may *write*.
     *
     * Used by the queries whose whole purpose is to feed a write — finding
     * overdue payment dates, finding trials to convert. Reading those through
     * the wider read predicate and then writing through the narrower one would
     * hand the caller a row it is about to be refused, and the refusal is an
     * exception rather than a no-op. A member who is merely a participant in a
     * split would therefore turn an ordinary dashboard load into a 404.
     *
     * @param array<string, mixed> $params
     */
    final protected function writableWhere(Scope $scope, Criteria $criteria, array &$params): string
    {
        $conditions = array_merge(
            [$this->scopePredicate($scope, $params)],
            $this->compileConditions($criteria, $params),
        );

        return ' WHERE ' . implode(' AND ', $conditions);
    }

    /**
     * @param array<string, mixed> $params
     */
    final protected function scopedWhere(Scope $scope, Criteria $criteria, array &$params): string
    {
        $conditions = array_merge(
            [$this->readPredicate($scope, $params)],
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

    /**
     * Whether a row is within scope *for writing*.
     *
     * Deliberately not expressed as a read: a caller about to modify a row must
     * ask the write predicate whether it may, and the read predicate can be
     * wider. Guards on mutating operations use this.
     */
    final protected function existsForWrite(Scope $scope, int $id): bool
    {
        $params = ['__id' => $id];
        $sql = 'SELECT 1 FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->qualify('id') . ' = :__id'
            . ' AND ' . $this->scopePredicate($scope, $params)
            . ' LIMIT 1';

        return $this->db->fetchValue($sql, $params) !== null;
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

        // A user whose writes are confined to their own rows may only create
        // rows they own — in ISOLATED mode, or as a Contributor. Otherwise they
        // could make a row they would then be refused when they tried to change
        // it, and in ISOLATED could not even see.
        if ($ownerColumn !== null && $scope->restrictsWritesToOwner()) {
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
        if ($ownerColumn !== null && $scope->restrictsWritesToOwner()) {
            // Handing a row to somebody else is itself a write outside your own
            // rows, so the column is not writable by anybody fenced to them.
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

        if ($this->db->execute($sql, $params) < 1 && !$this->existsForWrite($scope, $id)) {
            // Zero affected rows does not mean the same thing on both engines.
            // PostgreSQL counts matches, so zero means "no such row in scope".
            // MySQL counts actual changes, so an update writing the values a
            // row already holds also reports zero — and treating that as a
            // scope violation would turn a harmless no-op into a 404 on one
            // engine only. The second check settles which it was, and only
            // runs in the rare case where it matters.
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
        // Its two callers are a DELETE and an UPDATE, so it is the write
        // predicate wearing different quoting.
        return $this->householdAndOwner($scope, $params, $scope->restrictsWritesToOwner(), false);
    }
}
