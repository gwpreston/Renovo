<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Security\Scope;

/**
 * Dashboard figures.
 *
 * Totals are reported per currency and are never added together. Combining
 * them needs exchange rates, which this phase does not have; presenting a
 * single blended number without them would be wrong rather than approximate.
 *
 * One-off and lifetime entries are counted and totalled separately, because
 * amortising them over an arbitrary horizon would distort the recurring
 * figures that the whole dashboard is about.
 *
 * @phpstan-type CurrencyTotals array{currency: string, monthly_minor: int, yearly_minor: int, count: int}
 * @phpstan-type OneOffTotals array{currency: string, total_minor: int, count: int}
 * @phpstan-type CategoryTotals array{name: string, currency: string, monthly_minor: int, count: int}
 */
final class StatsService
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /**
     * @return array{
     *     recurring: list<CurrencyTotals>,
     *     one_off: list<OneOffTotals>,
     *     by_category: list<CategoryTotals>,
     *     active_count: int,
     *     upcoming_7: list<Subscription>,
     *     upcoming_30: list<Subscription>,
     *     upcoming_7_totals: list<OneOffTotals>,
     *     upcoming_30_totals: list<OneOffTotals>
     * }
     */
    public function dashboard(Scope $scope): array
    {
        // Bring anything overdue up to date first, so the upcoming-renewal
        // windows below do not silently miss a subscription whose date is in
        // the past.
        $this->subscriptions->advanceDuePayments($scope);

        $all = $this->subscriptions->allForStats($scope);

        $recurring = [];
        $oneOff = [];
        $byCategory = [];
        $activeCount = 0;

        foreach ($all as $subscription) {
            if (!$subscription->isActive) {
                continue;
            }

            $activeCount++;
            $currency = $subscription->price->currency;

            $monthly = $subscription->monthlyMinor();
            if ($monthly === null) {
                $oneOff[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
                $oneOff[$currency]['total_minor'] += $subscription->price->amountMinor;
                $oneOff[$currency]['count']++;
                continue;
            }

            $recurring[$currency] ??= [
                'currency' => $currency,
                'monthly_minor' => 0,
                'yearly_minor' => 0,
                'count' => 0,
            ];
            $recurring[$currency]['monthly_minor'] += $monthly;
            $recurring[$currency]['yearly_minor'] += $subscription->yearlyMinor() ?? 0;
            $recurring[$currency]['count']++;

            $categoryName = $subscription->categoryName ?? 'Uncategorised';
            $key = $categoryName . '|' . $currency;
            $byCategory[$key] ??= [
                'name' => $categoryName,
                'currency' => $currency,
                'monthly_minor' => 0,
                'count' => 0,
            ];
            $byCategory[$key]['monthly_minor'] += $monthly;
            $byCategory[$key]['count']++;
        }

        ksort($recurring);
        ksort($oneOff);
        uasort($byCategory, static fn (array $a, array $b): int => $b['monthly_minor'] <=> $a['monthly_minor']);

        $upcoming7 = $this->subscriptions->upcoming($scope, 7);
        $upcoming30 = $this->subscriptions->upcoming($scope, 30);

        return [
            'recurring' => array_values($recurring),
            'one_off' => array_values($oneOff),
            'by_category' => array_values($byCategory),
            'active_count' => $activeCount,
            'upcoming_7' => $upcoming7,
            'upcoming_30' => $upcoming30,
            'upcoming_7_totals' => $this->sumByCurrency($upcoming7),
            'upcoming_30_totals' => $this->sumByCurrency($upcoming30),
        ];
    }

    /**
     * The actual amounts that will be charged in a window — the face price of
     * each due payment, not a normalised figure.
     *
     * @param list<Subscription> $subscriptions
     * @return list<OneOffTotals>
     */
    public function sumByCurrency(array $subscriptions): array
    {
        $totals = [];

        foreach ($subscriptions as $subscription) {
            $currency = $subscription->price->currency;
            $totals[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
            $totals[$currency]['total_minor'] += $subscription->price->amountMinor;
            $totals[$currency]['count']++;
        }

        ksort($totals);

        return array_values($totals);
    }
}
