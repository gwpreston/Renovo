<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;

/**
 * Household-level administration: the name, and who holds which role.
 *
 * Role changes moved here from the settings controller when the audit log
 * arrived, and the move is the point rather than a side effect: "who was given
 * Editor, by whom, and when" is a fact about the operation, and it can only be
 * recorded reliably in the one place the operation happens.
 */
final class HouseholdSettingsService
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly AuditLogService $audit,
    ) {
    }

    public function rename(User $actor, int $householdId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $name = mb_substr($name, 0, 100);
        $this->households->rename($householdId, $name);

        $this->audit->record(AuditAction::HouseholdUpdated, $actor, ['name' => $name], $householdId);
    }

    /**
     * Apply a set of role changes, keyed by user id.
     *
     * An Owner may not change their own role here. Demoting yourself is how a
     * household ends up with nobody able to administer it, and the check is in
     * the service rather than the form so that an API request cannot skip it.
     *
     * @param array<array-key, mixed> $roles
     * @return int How many memberships actually changed.
     */
    public function changeRoles(User $actor, int $householdId, array $roles): int
    {
        $changed = 0;

        foreach ($roles as $userId => $roleValue) {
            $userId = (int) $userId;
            $role = Role::tryFrom(is_scalar($roleValue) ? (string) $roleValue : '');

            if ($role === null || $userId <= 0 || $userId === $actor->id) {
                continue;
            }

            $existing = $this->memberships->findForUserAndHousehold($userId, $householdId);
            if ($existing === null || $existing->role === $role) {
                continue;
            }

            $this->memberships->updateRole($householdId, $userId, $role);
            $changed++;

            $target = $this->users->findById($userId);

            $this->audit->record(
                AuditAction::RoleChanged,
                $actor,
                ['from' => $existing->role->value, 'to' => $role->value],
                $householdId,
                $userId,
                $target?->email,
            );
        }

        return $changed;
    }
}
