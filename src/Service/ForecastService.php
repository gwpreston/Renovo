<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Repository\PriceHistoryRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * What the next twelve months will actually cost.
 *
 * The forecast walks each subscription's billing dates forward one at a time
 * and records the charge that falls on each. It is not a monthly run-rate
 * multiplied by twelve, and the difference is the whole point: a yearly
 * subscription renewing in March is a bill in March, not a twelfth of itself
 * every month, and knowing which month it lands in is what makes the number
 * useful.
 *
 * **The invariant that everything else depends on.** The price used for a
 * charge is the price that will be in force on the date of that charge — the
 * scheduled price change that has been announced, the trial conversion that
 * has been recorded — never the subscription's current price extended across
 * the horizon.
 *
 * That is what keeps the forecast stable over time. As each of those dates
 * arrives, the catch-up applies the same change to the database that the
 * forecast has been predicting all along, so the figure for a given month does
 * not move. A forecast that read only `subscriptions.price_minor` would quietly
 * ignore every scheduled change and then jump on the day it took effect, and
 * budgets built on it would appear to drift for no reason anybody could see.
 *
 * @phpstan-type MonthTotals array{
 *     month: string,
 *     label: string,
 *     by_currency: array<string, int>,
 *     combined_minor: int|null,
 *     events: list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
 * }
 */
final class ForecastService
{
    /** A guard against a pathological cycle producing an unbounded walk. */
    private const MAX_CHARGES_PER_SUBSCRIPTION = 400;

    public const DEFAULT_MONTHS = 12;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryRepository $priceHistory,
        private readonly SplitService $splits,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Month-by-month totals for the horizon.
     *
     * @param int|null $forUserId When given, only that member's share of each
     *                            charge is counted — which is what a budget
     *                            needs and what a household overview does not.
     * @return list<MonthTotals>
     */
    public function monthly(Scope $scope, int $months = self::DEFAULT_MONTHS, ?int $forUserId = null): array
    {
        $today = $this->clock->today();
        $baseCurrency = $this->settings->baseCurrency();

        $buckets = $this->emptyMonths($today, $months);

        foreach ($this->charges($scope, $months, $forUserId) as $charge) {
            $key = $charge['date']->format('Y-m');
            if (!isset($buckets[$key])) {
                continue;
            }

            $currency = $charge['amount']->currency;
            $buckets[$key]['by_currency'][$currency] =
                ($buckets[$key]['by_currency'][$currency] ?? 0) + $charge['amount']->amountMinor;
            $buckets[$key]['events'][] = $charge;
        }

        foreach ($buckets as $key => $bucket) {
            ksort($bucket['by_currency']);
            // Null when any currency in the month has no rate: the degraded
            // display shows the per-currency figures instead of a total that
            // quietly left something out.
            $bucket['combined_minor'] = $bucket['by_currency'] === []
                ? 0
                : $this->rates->combine($bucket['by_currency'], $baseCurrency);
            $buckets[$key] = $bucket;
        }

        return array_values($buckets);
    }

    /**
     * Every individual charge expected in the horizon, in date order.
     *
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    public function charges(Scope $scope, int $months = self::DEFAULT_MONTHS, ?int $forUserId = null): array
    {
        $today = $this->clock->today();
        $horizon = $today->modify(sprintf('+%d months', $months));

        $subscriptions = $this->subscriptions->allForStats($scope);
        $scheduled = $this->priceHistory->findScheduledAfter($scope, $today);
        $splits = $forUserId === null ? [] : $this->splits->allInScope($scope);

        $charges = [];
        foreach ($subscriptions as $subscription) {
            if (!$subscription->isActive) {
                continue;
            }

            foreach ($this->chargesFor($subscription, $today, $horizon, $scheduled) as $charge) {
                if ($forUserId !== null) {
                    $participants = $splits[$subscription->id] ?? [];

                    // Whether they bear any of it is a separate question from
                    // how much they bear today. A trial costs everybody nothing
                    // right now, and its conversion is precisely the charge a
                    // per-member forecast exists to warn about.
                    if (!$this->splits->bears($subscription, $participants, $forUserId)) {
                        continue;
                    }

                    // The share is a proportion of the current price, applied
                    // to the price that will actually be charged, so a member
                    // paying a third still pays a third after an increase.
                    $charge['amount'] = $this->proportion(
                        $charge['amount'],
                        $subscription->price,
                        $this->splits->shareFor($subscription, $participants, $forUserId),
                    );
                }

                $charges[] = $charge;
            }
        }

        usort(
            $charges,
            static fn (array $a, array $b): int => $a['date'] <=> $b['date'] ?: $a['subscription']->id
                <=> $b['subscription']->id,
        );

        return $charges;
    }

    /**
     * Walk one subscription's charges across the horizon.
     *
     * @param array<int, list<PriceChange>> $scheduled
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function chargesFor(
        Subscription $subscription,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
        array $scheduled,
    ): array {
        // One-off and lifetime entries do not recur, so they contribute at most
        // the single charge already recorded against them.
        if (!$subscription->type->countsTowardsRecurringTotals()) {
            return $this->oneOffCharge($subscription, $today, $horizon);
        }

        $changes = $scheduled[$subscription->id] ?? [];

        if ($subscription->isTrial) {
            return $this->trialCharges($subscription, $today, $horizon, $changes);
        }

        $cycle = $subscription->billingCycle;
        $date = $subscription->nextPaymentDate;
        if ($cycle === null || $date === null) {
            return [];
        }

        $charges = [];
        $iterations = 0;

        while ($date <= $horizon && $iterations < self::MAX_CHARGES_PER_SUBSCRIPTION) {
            if ($date >= $today) {
                $charges[] = [
                    'subscription' => $subscription,
                    'date' => $date,
                    'amount' => $this->priceOn($subscription, $date, $changes),
                    'reason' => 'renewal',
                ];
            }

            $date = $cycle->advance($date, $subscription->cycleDays, $subscription->anchorDay);
            $iterations++;
        }

        return $charges;
    }

    /**
     * A trial's charges: nothing until it converts, then the converts-to price
     * on its own cycle.
     *
     * The conversion charge falls on the trial's last day, matching
     * TrialService exactly — the forecast and the catch-up must agree about the
     * date or the month a bill lands in would change the moment it happened.
     *
     * @param list<PriceChange> $changes
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function trialCharges(
        Subscription $subscription,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
        array $changes,
    ): array {
        $conversion = $subscription->trialEndDate;
        if ($conversion === null || $conversion > $horizon) {
            return [];
        }

        $cycle = $subscription->billingCycleAfterConversion();
        $price = $subscription->priceAfterConversion();
        if ($cycle === null) {
            return [];
        }

        $charges = [];
        $date = $conversion;
        $iterations = 0;

        while ($date <= $horizon && $iterations < self::MAX_CHARGES_PER_SUBSCRIPTION) {
            if ($date >= $today) {
                $scheduledPrice = $this->scheduledPriceOn($date, $changes);

                $charges[] = [
                    'subscription' => $subscription,
                    'date' => $date,
                    'amount' => $scheduledPrice ?? $price,
                    'reason' => $iterations === 0 ? 'trial_conversion' : 'renewal',
                ];
            }

            $date = $cycle->advance(
                $date,
                $subscription->cycleDaysAfterConversion(),
                (int) $conversion->format('j'),
            );
            $iterations++;
        }

        return $charges;
    }

    /**
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function oneOffCharge(
        Subscription $subscription,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
    ): array {
        $date = $subscription->nextPaymentDate;
        if ($date === null || $date < $today || $date > $horizon) {
            return [];
        }

        return [[
            'subscription' => $subscription,
            'date' => $date,
            'amount' => $subscription->price,
            'reason' => 'one_off',
        ]];
    }

    /**
     * The price that will be in force on a future date.
     *
     * @param list<PriceChange> $changes Future-dated changes, earliest first.
     */
    private function priceOn(Subscription $subscription, DateTimeImmutable $date, array $changes): Money
    {
        return $this->scheduledPriceOn($date, $changes) ?? $subscription->price;
    }

    /**
     * @param list<PriceChange> $changes
     */
    private function scheduledPriceOn(DateTimeImmutable $date, array $changes): ?Money
    {
        $price = null;
        foreach ($changes as $change) {
            if ($change->hasTakenEffectOn($date)) {
                // The list is in date order, so the last one to have taken
                // effect by this date is the one that applies.
                $price = $change->price;
                continue;
            }
            break;
        }

        return $price;
    }

    /**
     * Scale a future charge by the proportion a member bears today.
     *
     * Expressed as a ratio rather than as a stored amount so that an increase
     * is divided the same way the original was. If the current price is zero —
     * a trial — the member bears the whole of their converted charge, since
     * there is no ratio to take.
     */
    private function proportion(Money $charge, Money $currentPrice, Money $currentShare): Money
    {
        if ($currentPrice->isZero()) {
            return $charge;
        }

        return Money::of(
            Rounding::multiplyDivide(
                $charge->amountMinor,
                $currentShare->amountMinor,
                $currentPrice->amountMinor,
            ),
            $charge->currency,
        );
    }

    /**
     * @return array<string, MonthTotals>
     */
    private function emptyMonths(DateTimeImmutable $today, int $months): array
    {
        $buckets = [];
        $cursor = $today->modify('first day of this month');

        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->format('Y-m');
            $buckets[$key] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'by_currency' => [],
                'combined_minor' => 0,
                'events' => [],
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $buckets;
    }
}
