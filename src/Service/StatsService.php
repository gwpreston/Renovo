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
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-type UpcomingRow array{
 *     subscription: Subscription,
 *     date: DateTimeImmutable,
 *     days: int,
 *     is_urgent: bool
 * }
 */
final class StatsService
{
    private const MAX_HISTORIC_CHARGES = 500;

    /**
     * How many months of reconstructed spend the history chart shows.
     *
     * Twelve, to match the forecast's horizon: the two charts either side of a
     * dashboard are a year behind and a year ahead, and a reader comparing them
     * is comparing windows of the same length.
     */
    public const HISTORY_MONTHS = 12;

    /**
     * How far ahead the Coming soon card looks.
     *
     * Deliberately not `DashboardService::NEAR_WINDOW_DAYS`: that window is
     * fourteen days and answers "what is about to renew", which the metric
     * tile, the table's badges and the Expiring chip are all counted from. This
     * one answers a different question — "what is coming" — and a card that
     * shows a month and a half is a plan rather than a warning. Two questions,
     * two windows, neither pretending to be the other.
     */
    public const UPCOMING_WINDOW_DAYS = 45;

    /**
     * Inside this many days a row in that card stops being a plan.
     *
     * A month and a half of charges is a list to read; a charge this week is
     * one to act on, so the row says so in its own right rather than relying on
     * the reader to do the subtraction.
     */
    public const UPCOMING_URGENT_DAYS = 7;

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
     *     upcoming: list<UpcomingRow>,
     *     upcoming_totals: list<OneOffTotals>,
     *     upcoming_days: int,
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

        $upcoming = $this->upcomingRows($this->subscriptions->upcoming($scope, self::UPCOMING_WINDOW_DAYS));
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
            'upcoming' => $upcoming,
            // Summed from the rows the card actually draws, not from the query
            // behind them: a subscription dropped for want of a date would
            // otherwise be missing from the list and present in its total.
            'upcoming_totals' => $this->sumByCurrency(array_column($upcoming, 'subscription')),
            'upcoming_days' => self::UPCOMING_WINDOW_DAYS,
            'combined_monthly' => $this->combine($monthlyByCurrency),
            'combined_yearly' => $combinedYearly,
            'per_period' => $this->perPeriod($combinedYearly['amount_minor']),
            'trials' => $trials,
            'trial_totals' => $this->sumConvertedPrices($trials),
        ];
    }

    /**
     * The Coming soon rows: each charge with how far away it is.
     *
     * The countdown is computed here rather than in the template because it is
     * a judgement, not a formatting choice — how many days away a charge is,
     * and whether that is close enough to stop being a plan. A template that
     * worked it out would be the second place the threshold lived.
     *
     * Counted to `nextPaymentDate` because that is the column the query filters
     * and orders by. Counting to `nextChargeDate()` instead would number a
     * trial by its conversion date while the list around it was sorted by its
     * payment date, and the card would be ordered by one date and labelled with
     * another.
     *
     * @param list<Subscription> $upcoming
     * @return list<UpcomingRow>
     */
    private function upcomingRows(array $upcoming): array
    {
        $today = $this->clock->today();

        $rows = [];
        foreach ($upcoming as $subscription) {
            $date = $subscription->nextPaymentDate;
            $days = $subscription->daysUntilNextPayment($today);
            if ($date === null || $days === null) {
                continue;
            }

            $rows[] = [
                'subscription' => $subscription,
                'date' => $date,
                'days' => $days,
                'is_urgent' => $days <= self::UPCOMING_URGENT_DAYS,
            ];
        }

        return $rows;
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
     * Month-by-month spend over the window that has just been lived through.
     *
     * The mirror of `ForecastService::monthly()`, and shaped exactly like it so
     * that the same chart payload builder draws both: twelve buckets, the
     * oldest beginning on the first of the month eleven back, the newest
     * ending today.
     *
     * **This is reconstructed, not recorded**, in the same sense and by the
     * same code as `yearOverYear()` — the application tracks what is due, not a
     * ledger of what was paid, so the past is rebuilt from start dates, billing
     * cycles and recorded price history. Sharing `historicCharges()` with the
     * comparison card is the point: a chart and a total describing the same
     * twelve months are read off one reconstruction, so they cannot disagree
     * about last March. A subscription with no start date contributes nothing,
     * for the reason given there.
     *
     * Two properties of the window are worth stating because the chart depends
     * on them. The lower bound is the day before the oldest bucket opens, and
     * is exclusive, so a charge on the first of that month lands inside it
     * rather than being dropped. The upper bound is today, not the end of this
     * month, which is what makes the closing bucket a part month — the chart
     * draws it as one rather than as a collapse in spending.
     *
     * Every month is converted at today's rates, as the comparison card
     * already does. There is no history of rates to price a charge at the rate
     * that stood on its day, and inventing one would be a worse answer than a
     * consistent one.
     *
     * @return list<MonthTotals>
     */
    public function monthlyHistory(Scope $scope, int $months = self::HISTORY_MONTHS): array
    {
        $today = $this->clock->today();
        $baseCurrency = $this->settings->baseCurrency();

        $buckets = [];
        $cursor = $today->modify('first day of this month')->modify(sprintf('-%d months', $months - 1));
        $windowStart = $cursor;

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

        $from = $windowStart->modify('-1 day');

        foreach ($this->subscriptions->allForStats($scope, false) as $subscription) {
            foreach ($this->historicCharges($scope, $subscription, $from, $today) as $charge) {
                $key = $charge['date']->format('Y-m');
                if (!isset($buckets[$key])) {
                    continue;
                }

                $currency = $charge['amount']->currency;
                $buckets[$key]['by_currency'][$currency] =
                    ($buckets[$key]['by_currency'][$currency] ?? 0) + $charge['amount']->amountMinor;

                // Shaped like a forecast charge because the payload builder
                // takes forecast charges. `reason` is `historic` rather than
                // `renewal`: this is a charge the reconstruction believes took
                // place, not one the forecast is predicting, and labelling it
                // as the latter would make the two indistinguishable to
                // anything that ever reads the field.
                $buckets[$key]['events'][] = [
                    'subscription' => $subscription,
                    'date' => $charge['date'],
                    'amount' => $charge['amount'],
                    'reason' => 'historic',
                ];
            }
        }

        foreach ($buckets as $key => $bucket) {
            ksort($bucket['by_currency']);
            // Null when a currency in the month has no rate, exactly as the
            // forecast does it — which is what makes the chart refuse to draw
            // rather than slope towards a month it cannot total.
            $bucket['combined_minor'] = $bucket['by_currency'] === []
                ? 0
                : $this->rates->combine($bucket['by_currency'], $baseCurrency);
            $buckets[$key] = $bucket;
        }

        return array_values($buckets);
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
