<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;
use App\Support\Clock;

/**
 * The "worth it?" signal.
 *
 * The judgement this supports is a human one, so the application's job is to
 * put the right two numbers next to each other rather than to pronounce. Cost
 * per use, alongside what the thing costs a month, is enough for somebody to
 * look at a list and recognise the £15 service they last opened in March.
 *
 * A subscription with no usage recorded is not "bad value" — it is unmeasured,
 * and saying so is more honest than ranking it worst. The two are kept
 * distinct throughout.
 */
final class UsageService
{
    public const MAX_RATING = 5;

    /**
     * Below this many uses a month, something is worth a second look — provided
     * it also costs enough to be worth the bother.
     */
    private const LOW_USE_PER_MONTH = 1;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Record one use.
     */
    public function recordUse(Scope $scope, int $id): void
    {
        $this->subscriptions->incrementUsage($scope, $id, $this->clock->today());
    }

    /**
     * Set or clear the manual rating.
     */
    public function rate(Scope $scope, int $id, ?int $rating): void
    {
        if ($rating !== null && ($rating < 1 || $rating > self::MAX_RATING)) {
            $rating = null;
        }

        $this->subscriptions->setUsageRating($scope, $id, $rating);
    }

    /**
     * Start the count again from today.
     */
    public function reset(Scope $scope, int $id): void
    {
        $this->subscriptions->resetUsage($scope, $id, $this->clock->today());
    }

    /**
     * Subscriptions ranked by how poor the value looks, worst first.
     *
     * Only recurring, active entries with a usage count are ranked: a one-off
     * purchase has no monthly cost to divide, and something never recorded as
     * used is unmeasured rather than unused.
     *
     * @param list<Subscription> $subscriptions
     * @return list<array{
     *     subscription: Subscription,
     *     cost_per_use_minor: int|null,
     *     uses_per_month: float|null,
     *     is_low_use: bool
     * }>
     */
    public function valueSignals(array $subscriptions): array
    {
        $today = $this->clock->today();
        $signals = [];

        foreach ($subscriptions as $subscription) {
            if (!$subscription->isActive || !$subscription->type->countsTowardsRecurringTotals()) {
                continue;
            }

            $monthly = $subscription->monthlyMinor();
            if ($monthly === null) {
                continue;
            }

            $costPerUse = $subscription->costPerUseMinor($today);
            $usesPerMonth = $subscription->usageCount > 0
                ? $subscription->usageCount / $subscription->usageMonths($today)
                : null;

            $signals[] = [
                'subscription' => $subscription,
                'cost_per_use_minor' => $costPerUse,
                'uses_per_month' => $usesPerMonth,
                // Low use only counts as a signal when the thing costs enough
                // to be worth acting on. Nobody needs telling that a 79p app
                // they open twice a year is poor value.
                'is_low_use' => $usesPerMonth !== null
                    && $usesPerMonth < self::LOW_USE_PER_MONTH
                    && $monthly >= 100,
            ];
        }

        // Highest cost per use first; anything unmeasured sorts to the end,
        // where it reads as "no data" rather than as "best value".
        usort($signals, static function (array $a, array $b): int {
            $left = $a['cost_per_use_minor'];
            $right = $b['cost_per_use_minor'];

            if ($left === null && $right === null) {
                return $b['subscription']->monthlyMinor() <=> $a['subscription']->monthlyMinor();
            }
            if ($left === null) {
                return 1;
            }
            if ($right === null) {
                return -1;
            }

            return $right <=> $left;
        });

        return $signals;
    }
}
