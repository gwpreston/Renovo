<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Repository\HouseholdRepository;

/**
 * Household-level administration: the household's own settings.
 *
 * Just the name, since Phase 15. Role changes used to live here too, and moved
 * to `HouseholdMemberService` when the member screen arrived — not for tidiness
 * but because a role change is one of three operations that can leave a
 * household with no Owner, and the guard against that has to be in one place
 * for all three. Two services able to change a role would have been two places
 * to remember it.
 */
final class HouseholdSettingsService
{
    public function __construct(
        private readonly HouseholdRepository $households,
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
}
