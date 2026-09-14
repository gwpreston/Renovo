<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Repository\PriceHistoryRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Dashboard figures.
 *
 * Per-currency subtotals are still the truth of the matter and are always
 * shown. A combined total is offered *alongside* them when every currency in
 * play can be converted; when one cannot, the combined figure is withheld
 * rather than quietly computed from the part that could be. A total that
 * silently omits a currency is a wrong number, not an approximate one, and the
 * name of the missing currency is carried through so the UI can say why.
 *
 * One-off and lifetime entries are counted and totalled separately, because
 * amortising them over an arbitrary horizon would distort the recurring figures
 * that the whole dashboard is about.
 *
 * @phpstan-type CurrencyTotals array{currency: string, monthly_minor: int, yearly_minor: int, count: int}
 * @phpstan-type OneOffTotals array{currency: string, total_minor: int, count: int}
 * @phpstan-type CategoryTotals array{name: string, currency: string, monthly_minor: int, count: int}
 * @phpstan-type Combined array{currency: string, amount_minor: int|null, unconvertible: list<string>}
 */
final class StatsService
{
    private const MAX_HISTORIC_CHARGES = 500;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly CatchUpService $catchUp,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly PriceHistoryRepository $priceHistory,
        private readonly Clock $clock,
    ) {
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
     *     upcoming_30_totals: list<OneOffTotals>,
     *     combined_monthly: Combined,
     *     combined_yearly: Combined,
     *     per_period: array<string, int|null>,
     *     trials: list<Subscription>,
     *     trial_totals: list<OneOffTotals>
     * }
     */
    public function dashboard(Scope $scope): array
    {
        // Bring everything that has drifted up to date first — due price
        // changes, ended trials, overdue payment dates, in that order — so the
        // figures below are computed from current prices and no upcoming
        // window silently misses a subscription whose date is in the past.
        $this->catchUp->run($scope);

        // Best-effort and never blocking: a provider being down means
        // per-currency subtotals today and a combined total again tomorrow.
        $this->rates->refreshIfStale();

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
        $trials = $this->subscriptions->trialsEndingSoon($scope, 30);

        $monthlyByCurrency = [];
        $yearlyByCurrency = [];
        foreach ($recurring as $currency => $row) {
            $monthlyByCurrency[(string) $currency] = $row['monthly_minor'];
            $yearlyByCurrency[(string) $currency] = $row['yearly_minor'];
        }

        $combinedYearly = $this->combine($yearlyByCurrency);

        return [
            'recurring' => array_values($recurring),
            'one_off' => array_values($oneOff),
            'by_category' => array_values($byCategory),
            'active_count' => $activeCount,
            'upcoming_7' => $upcoming7,
            'upcoming_30' => $upcoming30,
            'upcoming_7_totals' => $this->sumByCurrency($upcoming7),
            'upcoming_30_totals' => $this->sumByCurrency($upcoming30),
            'combined_monthly' => $this->combine($monthlyByCurrency),
            'combined_yearly' => $combinedYearly,
            'per_period' => $this->perPeriod($combinedYearly['amount_minor']),
            'trials' => $trials,
            'trial_totals' => $this->sumConvertedPrices($trials),
        ];
    }

    /**
     * The same recurring cost expressed over each period people think in.
     *
     * All four are derived from the annual figure rather than from one another,
     * so a report cannot show a weekly number that fails to multiply up to its
     * own yearly one. The daily and weekly figures use the mean Gregorian year
     * for the same reason BillingCycle does.
     *
     * @return array<string, int|null>
     */
    public function perPeriod(?int $yearlyMinor): array
    {
        if ($yearlyMinor === null) {
            return ['daily' => null, 'weekly' => null, 'monthly' => null, 'yearly' => null];
        }

        return [
            'daily' => Rounding::multiplyDivide($yearlyMinor, 100, BillingCycle::DAYS_PER_YEAR_X100),
            'weekly' => Rounding::multiplyDivide($yearlyMinor, 700, BillingCycle::DAYS_PER_YEAR_X100),
            'monthly' => Rounding::divide($yearlyMinor, 12),
            'yearly' => $yearlyMinor,
        ];
    }

    /**
     * Spend over the last twelve months against the twelve before that.
     *
     * **This is reconstructed, not recorded.** The application keeps no ledger
     * of payments taken — it tracks what is due, not what has been paid — so
     * the comparison is rebuilt from what is actually known: each subscription's
     * start date, its billing cycle, and the price history saying what it cost
     * on each of those dates.
     *
     * That has one consequence worth stating rather than leaving to be
     * discovered. A subscription with no start date contributes nothing to
     * either window, because there is no evidence of when it began.
     * Under-reporting the past is the right direction to be wrong in: it makes
     * a year-on-year rise look smaller than it was, rather than inventing
     * spending that may never have happened.
     *
     * @return array{
     *     current: Combined,
     *     previous: Combined,
     *     change_minor: int|null,
     *     change_percent: int|null,
     *     current_by_currency: array<string, int>,
     *     previous_by_currency: array<string, int>,
     *     excluded_count: int
     * }
     */
    public function yearOverYear(Scope $scope): array
    {
        $today = $this->clock->today();
        $currentFrom = $today->modify('-1 year');
        $previousFrom = $today->modify('-2 years');

        $currentByCurrency = [];
        $previousByCurrency = [];
        $excluded = 0;

        foreach ($this->subscriptions->allForStats($scope, false) as $subscription) {
            if ($subscription->startDate === null) {
                $excluded++;
                continue;
            }

            foreach ($this->historicCharges($scope, $subscription, $previousFrom, $today) as $charge) {
                $currency = $charge['amount']->currency;

                // The boundary is exclusive at the start and inclusive at the
                // end. A charge falling exactly a year ago today belongs to the
                // earlier window, not to both — counting it in the current one
                // would put thirteen monthly charges in a twelve-month year and
                // make every comparison overstate the recent side.
                if ($charge['date'] > $currentFrom) {
                    $currentByCurrency[$currency] = ($currentByCurrency[$currency] ?? 0)
                        + $charge['amount']->amountMinor;
                } else {
                    $previousByCurrency[$currency] = ($previousByCurrency[$currency] ?? 0)
                        + $charge['amount']->amountMinor;
                }
            }
        }

        ksort($currentByCurrency);
        ksort($previousByCurrency);

        $current = $this->combine($currentByCurrency);
        $previous = $this->combine($previousByCurrency);

        $change = null;
        $changePercent = null;
        if ($current['amount_minor'] !== null && $previous['amount_minor'] !== null) {
            $change = $current['amount_minor'] - $previous['amount_minor'];
            $changePercent = $previous['amount_minor'] > 0
                ? Rounding::multiplyDivide($change, 100, $previous['amount_minor'])
                : null;
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'change_minor' => $change,
            'change_percent' => $changePercent,
            'current_by_currency' => $currentByCurrency,
            'previous_by_currency' => $previousByCurrency,
            'excluded_count' => $excluded,
        ];
    }

    /**
     * Reconstruct the charges a subscription took in `($from, $to]`.
     *
     * The lower bound is exclusive so that two adjacent twelve-month windows
     * partition the charges rather than sharing the one on the boundary.
     *
     * Walks forward from the start date rather than backwards from the next
     * payment, because the start date is the only evidence there is that a
     * subscription existed at a given moment. Each charge is priced at whatever
     * the history says it cost on that day, so a subscription that was cheaper
     * last year is counted at last year's price — which is the entire point of
     * comparing one year with another.
     *
     * @return list<array{date: DateTimeImmutable, amount: Money}>
     */
    private function historicCharges(
        Scope $scope,
        Subscription $subscription,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $start = $subscription->startDate;
        if ($start === null) {
            return [];
        }

        if (!$subscription->type->countsTowardsRecurringTotals()) {
            // A one-off lands on its own date and nowhere else.
            return $start > $from && $start <= $to
                ? [['date' => $start, 'amount' => $subscription->price]]
                : [];
        }

        $cycle = $subscription->billingCycle;
        if ($cycle === null) {
            return [];
        }

        $history = $this->priceHistory->findForSubscription($scope, $subscription->id);

        $charges = [];
        $date = $start;
        $iterations = 0;

        while ($date <= $to && $iterations < self::MAX_HISTORIC_CHARGES) {
            if ($date > $from) {
                $charges[] = [
                    'date' => $date,
                    'amount' => $this->priceAsAt($history, $date) ?? $subscription->price,
                ];
            }

            $date = $cycle->advance($date, $subscription->cycleDays, $subscription->anchorDay);
            $iterations++;
        }

        return $charges;
    }

    /**
     * @param list<PriceChange> $history Oldest first.
     */
    private function priceAsAt(array $history, DateTimeImmutable $date): ?Money
    {
        $price = null;
        foreach ($history as $change) {
            if (!$change->hasTakenEffectOn($date)) {
                break;
            }
            $price = $change->price;
        }

        return $price;
    }

    /**
     * Convert a set of per-currency amounts into the base currency, naming the
     * currencies that could not be converted rather than dropping them.
     *
     * @param array<string, int> $byCurrency
     * @return Combined
     */
    public function combine(array $byCurrency): array
    {
        $base = $this->settings->baseCurrency();

        $unconvertible = [];
        foreach (array_keys($byCurrency) as $currency) {
            if ($this->rates->rateFor((string) $currency, $base) === null) {
                $unconvertible[] = (string) $currency;
            }
        }

        sort($unconvertible);

        return [
            'currency' => $base,
            'amount_minor' => $unconvertible === [] ? ($this->rates->combine($byCurrency, $base) ?? 0) : null,
            'unconvertible' => $unconvertible,
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

    /**
     * The same, but for trials — using what they will cost once they convert,
     * which is the only figure anybody is interested in.
     *
     * @param list<Subscription> $trials
     * @return list<OneOffTotals>
     */
    public function sumConvertedPrices(array $trials): array
    {
        $totals = [];

        foreach ($trials as $trial) {
            $price = $trial->priceAfterConversion();
            $currency = $price->currency;
            $totals[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
            $totals[$currency]['total_minor'] += $price->amountMinor;
            $totals[$currency]['count']++;
        }

        ksort($totals);

        return array_values($totals);
    }
}
