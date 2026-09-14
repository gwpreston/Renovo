<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\SubscriptionSplit;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Shared-cost split participants.
 *
 * Scoped like the subscriptions they belong to, and — like them — widened for
 * reads by the same participant rule. The widening is genuinely needed here,
 * not merely inherited: a split row's `owner_user_id` mirrors the
 * subscription's owner, so in ISOLATED mode a participant's own row would be
 * invisible to them without it, and they would see a subscription whose
 * breakdown was blank.
 *
 * A consequence worth stating rather than discovering: a participant sees
 * *every* share on a subscription they are part of, not only their own. That is
 * deliberate. A split is an agreement between the people named in it, and
 * showing somebody their share of a bill while hiding what everybody else is
 * paying would make the figure impossible to check.
 */
final class SplitRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'subscription_splits';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'subscription_id', 'household_id', 'owner_user_id', 'user_id', 'share_units'];
    }

    /**
     * A participant may see the splits of a subscription they contribute to,
     * for the same reason they may see the subscription itself: they are paying
     * part of it, and a share they cannot inspect is not much use to them.
     *
     * Load-bearing, not decorative. Split rows carry the subscription owner's
     * id, so without this a participant in ISOLATED mode would be unable to
     * read even the row describing their own share.
     *
     * Read-only, like every use of this hook — see AbstractScopedRepository.
     * Removing a participant, or changing their weight, goes through the write
     * predicate and is refused.
     *
     * @param array<string, mixed> $params
     */
    protected function readVisibilityPredicate(Scope $scope, array &$params): string
    {
        $params['__participant'] = $scope->userId;

        return 'EXISTS (SELECT 1 FROM ' . $this->quote('subscription_splits') . ' mine'
            . ' WHERE mine.' . $this->quote('subscription_id') . ' = ' . $this->qualify('subscription_id')
            . ' AND mine.' . $this->quote('user_id') . ' = :__participant)';
    }

    /**
     * @return list<SubscriptionSplit>
     */
    public function findForSubscription(Scope $scope, int $subscriptionId): array
    {
        $criteria = Criteria::new()
            ->equals('subscription_id', $subscriptionId)
            ->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithMember()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /**
     * Every split in scope, keyed by subscription id — one query for a whole
     * dashboard rather than one per subscription.
     *
     * @return array<int, list<SubscriptionSplit>>
     */
    public function findAllInScope(Scope $scope): array
    {
        $criteria = Criteria::new()->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithMember()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        $grouped = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $grouped[(int) $row['subscription_id']][] = $this->hydrate($row);
        }

        return $grouped;
    }

    /**
     * Replace a subscription's participants wholesale.
     *
     * The caller must already have established that the subscription is
     * writable in this scope — SplitService does, through the subscription
     * repository's write path — because this is the point at which a
     * participant could otherwise add themselves to somebody else's
     * subscription.
     *
     * @param list<array{user_id: int, share_units: int}> $participants
     */
    public function replaceForSubscription(
        Scope $scope,
        int $subscriptionId,
        int $ownerUserId,
        array $participants,
    ): void {
        $this->db->transactional(function () use ($scope, $subscriptionId, $ownerUserId, $participants): void {
            $this->deleteForSubscription($scope, $subscriptionId);

            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($participants as $participant) {
                $this->insertScoped($scope, [
                    'subscription_id' => $subscriptionId,
                    'user_id' => $participant['user_id'],
                    'share_units' => $participant['share_units'],
                    'created_at' => $now,
                    'updated_at' => $now,
                    'owner_user_id' => $ownerUserId,
                ]);
            }
        });
    }

    /**
     * Remove every participant from a subscription.
     *
     * Deliberately keyed on the *write* predicate rather than the read one: a
     * participant who can see a split must not be able to delete it.
     */
    public function deleteForSubscription(Scope $scope, int $subscriptionId): void
    {
        $params = ['__subscription' => $subscriptionId];

        $this->db->execute(
            'DELETE FROM ' . $this->quote('subscription_splits')
            . ' WHERE ' . $this->quote('subscription_id') . ' = :__subscription'
            . ' AND ' . $this->scopePredicateUnqualified($scope, $params),
            $params,
        );
    }

    private function selectWithMember(): string
    {
        $table = $this->quote('subscription_splits');

        return 'SELECT ' . $table . '.*,'
            . ' member.' . $this->quote('display_name') . ' AS user_name'
            . ' FROM ' . $table
            . ' LEFT JOIN ' . $this->quote('users') . ' member'
            . ' ON member.' . $this->quote('id') . ' = ' . $this->qualify('user_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SubscriptionSplit
    {
        return new SubscriptionSplit(
            id: (int) $row['id'],
            subscriptionId: (int) $row['subscription_id'],
            userId: (int) $row['user_id'],
            shareUnits: (int) $row['share_units'],
            userName: ($row['user_name'] ?? null) === null ? null : (string) $row['user_name'],
        );
    }
}
