<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\HouseholdLabel;
use App\Domain\Permission;
use App\Domain\RatesChip;
use App\Domain\RatesState;
use App\Domain\ShellContext;
use App\Domain\SubscriptionFilter;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Security\PermissionService;
use App\Security\Scope;

/**
 * The rail's and the top bar's context, so neither a controller nor a template
 * has to work any of it out.
 *
 * Three reads and a status, all of them scoped:
 *
 *  - the household label — its name, how many people are in it, and the
 *    reader's own role in it;
 *  - the Subscriptions badge — the same count the list itself would give this
 *    reader with no filter applied, so in ISOLATED mode it is their own rows
 *    and the splits they share, never the household's total;
 *  - the bell's dot — whether a trial converts or a cancel-by deadline falls
 *    inside the urgent window. Not plain renewals: nearly every household has
 *    one in any fortnight, and a dot that is always lit says nothing.
 *
 * and the rates chip, which reads what the rate cache records about itself and
 * never asks it to fetch.
 */
final class ShellService
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly SubscriptionRepository $subscriptions,
        private readonly CancellationService $cancellations,
        private readonly TrialService $trials,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly PermissionService $permissions,
    ) {
    }

    public function forScope(Scope $scope): ShellContext
    {
        $canRead = $this->permissions->allows($scope, Permission::ViewSubscriptions);

        return new ShellContext(
            $this->household($scope),
            $canRead ? $this->subscriptions->countForList($scope, new SubscriptionFilter()) : null,
            $this->ratesChip($scope),
            $canRead && $this->somethingDueSoon($scope),
        );
    }

    private function household(Scope $scope): ?HouseholdLabel
    {
        if ($scope->householdId === null || $scope->role === null) {
            return null;
        }

        $household = $this->households->findById($scope->householdId);
        if ($household === null) {
            return null;
        }

        return new HouseholdLabel(
            $household->name,
            $this->memberships->countActiveMembers($household->id),
            $scope->role,
        );
    }

    /**
     * Fresh, stale or unavailable, from the cache's own record of when it was
     * last filled. "Stale" is the cache's own definition — older than its TTL
     * — so the chip and the refresh job cannot disagree about it.
     */
    private function ratesChip(Scope $scope): RatesChip
    {
        $refreshedAt = $this->rates->lastRefreshedAt();

        $state = match (true) {
            $refreshedAt === null => RatesState::Unavailable,
            $this->rates->isStale() => RatesState::Stale,
            default => RatesState::Fresh,
        };

        return new RatesChip(
            $this->settings->baseCurrency(),
            $state,
            $refreshedAt,
            $this->permissions->allows($scope, Permission::ManageInstance),
        );
    }

    private function somethingDueSoon(Scope $scope): bool
    {
        if ($this->trials->endingSoon($scope, CancellationService::URGENT_DAYS) !== []) {
            return true;
        }

        return $this->cancellations->urgentCount($scope) > 0;
    }
}
