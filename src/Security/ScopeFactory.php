<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Entity\User;
use App\Repository\MembershipRepository;
use App\Service\InstanceSettingsService;

/**
 * Builds the request's Scope.
 *
 * This is the only place a Scope is constructed from live data, which is what
 * lets the rules it encodes — the household, the role, the isolation mode — be
 * reasoned about in one reading rather than traced through every route.
 */
final class ScopeFactory
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly InstanceSettingsService $settings,
    ) {
    }

    /**
     * @param int|null $preferredHouseholdId The household the user last had
     *        selected, if they belong to more than one.
     */
    public function forUser(User $user, ?int $preferredHouseholdId = null): Scope
    {
        $memberships = $this->memberships->findAllForUser($user->id);
        $isolation = $this->settings->isolationMode();

        if ($memberships === []) {
            // An instance admin who belongs to no household lands here, and
            // sees no household data at all. That is the intended behaviour,
            // not an oversight: administering an instance is not the same
            // thing as being entitled to read what is in it.
            return Scope::withoutHousehold($user->id, $user->isInstanceAdmin, $isolation);
        }

        $membership = $memberships[0];
        if ($preferredHouseholdId !== null) {
            foreach ($memberships as $candidate) {
                if ($candidate->householdId === $preferredHouseholdId) {
                    $membership = $candidate;
                    break;
                }
            }
        }

        return Scope::forMember(
            $user->id,
            $user->isInstanceAdmin,
            $membership->householdId,
            $membership->role,
            $isolation,
        );
    }
}
