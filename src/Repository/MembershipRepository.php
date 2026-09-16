<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\Membership;
use App\Domain\Role;
use DateTimeImmutable;

/**
 * Memberships decide what a Scope contains, so this repository is consulted
 * before a scope exists and is therefore unscoped by necessity. It never
 * returns household *data* — only which households a given user belongs to and
 * with what role.
 */
final class MembershipRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'household_memberships';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'household_id', 'user_id', 'role'];
    }

    public function create(int $householdId, int $userId, Role $role): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->insert('household_memberships', [
            'household_id' => $householdId,
            'user_id' => $userId,
            'role' => $role->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function findForUserAndHousehold(int $userId, int $householdId): ?Membership
    {
        $row = $this->db->fetchOne(
            'SELECT m.*, h.' . $this->quote('name') . ' AS household_name'
            . ' FROM ' . $this->quote('household_memberships') . ' m'
            . ' INNER JOIN ' . $this->quote('households') . ' h ON h.' . $this->quote('id')
            . ' = m.' . $this->quote('household_id')
            . ' WHERE m.' . $this->quote('user_id') . ' = :user'
            . ' AND m.' . $this->quote('household_id') . ' = :household',
            ['user' => $userId, 'household' => $householdId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<Membership>
     */
    public function findAllForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.*, h.' . $this->quote('name') . ' AS household_name'
            . ' FROM ' . $this->quote('household_memberships') . ' m'
            . ' INNER JOIN ' . $this->quote('households') . ' h ON h.' . $this->quote('id')
            . ' = m.' . $this->quote('household_id')
            . ' WHERE m.' . $this->quote('user_id') . ' = :user'
            . ' ORDER BY h.' . $this->quote('name') . ' ASC',
            ['user' => $userId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Members of a household, for the owner/payer pick-lists.
     *
     * @return list<array{id: int, display_name: string, email: string, role: string}>
     */
    public function findMembersOfHousehold(int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.' . $this->quote('id') . ' AS id, u.' . $this->quote('display_name') . ' AS display_name,'
            . ' u.' . $this->quote('email') . ' AS email,'
            . ' m.' . $this->quote('role') . ' AS role'
            . ' FROM ' . $this->quote('household_memberships') . ' m'
            . ' INNER JOIN ' . $this->quote('users') . ' u ON u.' . $this->quote('id')
            . ' = m.' . $this->quote('user_id')
            . ' WHERE m.' . $this->quote('household_id') . ' = :household'
            . ' ORDER BY u.' . $this->quote('display_name') . ' ASC',
            ['household' => $householdId],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'display_name' => (string) $row['display_name'],
                // Carried because a backup identifies members by address: ids
                // mean nothing in the instance an archive is restored into.
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
            ],
            $rows,
        );
    }

    public function updateRole(int $householdId, int $userId, Role $role): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('household_memberships') . ' SET ' . $this->quote('role') . ' = :role, '
            . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('user_id') . ' = :user',
            [
                'role' => $role->value,
                'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'household' => $householdId,
                'user' => $userId,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Membership
    {
        return new Membership(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            userId: (int) $row['user_id'],
            role: Role::from((string) $row['role']),
            householdName: isset($row['household_name']) ? (string) $row['household_name'] : null,
        );
    }
}
