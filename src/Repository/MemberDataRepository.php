<?php

declare(strict_types=1);

namespace App\Repository;

use App\Security\Scope;
use App\Security\ScopeViolationException;

/**
 * What happens to one member's household rows, and their membership, when they
 * are removed.
 *
 * A repository of its own because the operation crosses every table that
 * carries an `owner_user_id`, and doing it one scoped call at a time is
 * impossible by construction: in ISOLATED mode the Owner running the removal
 * cannot see the departing member's rows, so a scoped UPDATE would match
 * nothing and the rows would be left pointing at somebody who is no longer
 * here.
 *
 * That is why every method takes a Scope and refuses one that is not an
 * Owner/Admin of the household it names — the same shape as
 * `AuditLogRepository`'s administrator reads. The scope is still the
 * authority; what changes is the question asked of it. "May this person
 * administer this household?" is not the same question as "may they read this
 * row?", and removal is the first operation that needs the former.
 *
 * The tables are listed once, here. A later phase that adds another table with
 * an `owner_user_id` has exactly one place to add it, and the absence of the
 * row it forgot would otherwise show up as a foreign key pointing at a
 * stranger.
 *
 * The whole removal is one transaction, including the membership row, which is
 * why that row is deleted here rather than through `MembershipRepository`. A
 * removal that got halfway is the worst of the possible outcomes: a member
 * whose subscriptions have moved but who is still in the household, or one who
 * has left and whose split shares went with them while their subscriptions did
 * not. Files are the one thing outside it, because deleting a file cannot be
 * rolled back — so the paths are collected inside and handed to the caller to
 * unlink once the rows are actually gone.
 */
final class MemberDataRepository extends AbstractRepository
{
    /**
     * Every household table whose rows belong to an individual member.
     *
     * Order matters for deletion and not for reassignment: `subscriptions` and
     * `budgets` are the parents, and the rest cascade from them, so deleting
     * those two is enough. Reassignment touches all of them, because a child
     * row carries its own copy of the owner and a half-reassigned tree is
     * exactly the dangling reference this class exists to prevent.
     *
     * @var list<string>
     */
    private const OWNED_TABLES = [
        'subscriptions',
        'subscription_price_history',
        'subscription_splits',
        'attachments',
        'budgets',
        'budget_alert_state',
    ];

    protected function table(): string
    {
        return 'subscriptions';
    }

    protected function filterableColumns(): array
    {
        return ['household_id', 'owner_user_id'];
    }

    /**
     * How many rows one member owns in this household.
     *
     * Asked before the removal form is drawn, so the admin is offered the
     * reassign-or-delete choice only when there is something to choose about.
     */
    public function countOwnedBy(Scope $scope, int $userId): int
    {
        $householdId = $this->assertAdministrator($scope);

        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('subscriptions')
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('owner_user_id') . ' = :user',
            ['household' => $householdId, 'user' => $userId],
        );
    }

    /**
     * Take a member out of the household, rows and all, in one transaction.
     *
     * The order inside matters. Their split participations go first and
     * unconditionally — a split is a *read grant*, so one left behind leaves a
     * removed member able to see into the household they were removed from,
     * whatever happened to the rows they owned. Then the rows they owned are
     * either handed over or deleted, and only then does the membership go.
     *
     * @param bool $deleteData Delete what they owned instead of reassigning it.
     *                         The caller decides whether the isolation mode
     *                         makes that question worth asking.
     * @return list<string> The attachment paths whose files the caller must now
     *                      unlink. Empty unless the rows were deleted.
     */
    public function removeFromHousehold(Scope $scope, int $userId, int $reassignTo, bool $deleteData): array
    {
        $householdId = $this->assertAdministrator($scope);

        return $this->db->transactional(function () use ($householdId, $userId, $reassignTo, $deleteData): array {
            $this->db->execute(
                'DELETE FROM ' . $this->quote('subscription_splits')
                . ' WHERE ' . $this->quote('household_id') . ' = :household'
                . ' AND ' . $this->quote('user_id') . ' = :user',
                ['household' => $householdId, 'user' => $userId],
            );

            $paths = $deleteData
                ? $this->purge($householdId, $userId)
                : $this->reassign($householdId, $userId, $reassignTo);

            $this->db->execute(
                'DELETE FROM ' . $this->quote('household_memberships')
                . ' WHERE ' . $this->quote('household_id') . ' = :household'
                . ' AND ' . $this->quote('user_id') . ' = :user',
                ['household' => $householdId, 'user' => $userId],
            );

            return $paths;
        });
    }

    /**
     * Hand a departing member's rows to somebody who is staying.
     *
     * Every table, not just the parents: a child row carries its own copy of
     * the owner, and a half-reassigned tree is exactly the dangling reference
     * this class exists to prevent.
     *
     * @return list<string> Always empty — nothing is deleted, so no file is.
     */
    private function reassign(int $householdId, int $fromUserId, int $toUserId): array
    {
        foreach (self::OWNED_TABLES as $table) {
            $this->db->execute(
                'UPDATE ' . $this->quote($table) . ' SET ' . $this->quote('owner_user_id') . ' = :to'
                . ' WHERE ' . $this->quote('household_id') . ' = :household'
                . ' AND ' . $this->quote('owner_user_id') . ' = :from',
                ['to' => $toUserId, 'household' => $householdId, 'from' => $fromUserId],
            );
        }

        return [];
    }

    /**
     * Delete everything a departing member owns in this household.
     *
     * Only the two parents are deleted: price history, splits, attachments and
     * alert state all carry `ON DELETE CASCADE` back to a subscription or a
     * budget, so naming them here as well would be a second, weaker copy of a
     * rule the schema already enforces.
     *
     * The attachment paths are read *before* the delete, because the cascade is
     * about to take away the only record of where those files are.
     *
     * @return list<string> The files the caller must unlink afterwards.
     */
    private function purge(int $householdId, int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('stored_path') . ' FROM ' . $this->quote('attachments')
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('owner_user_id') . ' = :user',
            ['household' => $householdId, 'user' => $userId],
        );

        $paths = array_values(array_map(static fn (array $row): string => (string) $row['stored_path'], $rows));

        foreach (['subscriptions', 'budgets'] as $table) {
            $this->db->execute(
                'DELETE FROM ' . $this->quote($table)
                . ' WHERE ' . $this->quote('household_id') . ' = :household'
                . ' AND ' . $this->quote('owner_user_id') . ' = :user',
                ['household' => $householdId, 'user' => $userId],
            );
        }

        return $paths;
    }

    /**
     * @return int The household this scope administers.
     * @throws ScopeViolationException when it administers none.
     */
    private function assertAdministrator(Scope $scope): int
    {
        if (!$scope->hasHousehold() || !$scope->canManageHousehold()) {
            throw new ScopeViolationException(
                'Only an Owner/Admin of this household may move or remove a member\'s data.',
            );
        }

        return (int) $scope->householdId;
    }
}
