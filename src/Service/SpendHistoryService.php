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
 * What the past cost, reconstructed.
 *
 * Renovo keeps no ledger: it tracks what is due, not what was paid. Everything
 * the application says about spending that has already happened — the
 * year-over-year comparison, the months behind on a chart, the year to date —
 * is therefore rebuilt from what is known: each subscription's start date, its
 * billing cycle, and the price history saying what it cost on each of those
 * dates. This is the one place that rebuilding is done, so no two screens can
 * disagree about what last March cost.
 *
 * Every caller asks the same question underneath — which charges fell in a
 * window — and `charges()` answers it with the count of subscriptions that
 * could not be placed for having no start date, so each screen can say how
 * many it left out rather than quietly showing less.
 *
 * @phpstan-import-type Combined from StatsService
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-type HistoricCharge array{
 *     subscription: Subscription,
 *     date: DateTimeImmutable,
 *     amount: Money,
 *     reason: string
 * }
 */
final class SpendHistoryService
{
    private const MAX_HISTORIC_CHARGES = 500;

    /**
     * How many months of reconstructed spend the history chart shows.
     *
     * Twelve, to match the forecast's horizon: a year behind and a year ahead
     * are windows of the same length, so a reader comparing them is comparing
     * like with like.
     */
    public const MONTHS = 12;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryRepository $priceHistory,
        private readonly StatsService $stats,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Every charge the reconstruction places in `($from, $to]`, in date order,
     * and how many subscriptions it could not place at all.
     *
     * The lower bound is exclusive so that two adjacent windows partition the
     * charges rather than sharing the one on their boundary.
     *
     * @return array{charges: list<HistoricCharge>, excluded_count: int}
     */
    public function charges(Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $charges = [];
        $excluded = 0;

        foreach ($this->subscriptions->allForStats($scope, false) as $subscription) {
            if ($subscription->startDate === null) {
                $excluded++;
                continue;
            }

            foreach ($this->historicCharges($scope, $subscription, $from, $to) as $charge) {
                $charges[] = [
                    'subscription' => $subscription,
                    'date' => $charge['date'],
                    'amount' => $charge['amount'],
                    'reason' => 'historic',
                ];
            }
        }

        usort(
            $charges,
            static fn (array $a, array $b): int => $a['date'] <=> $b['date']
                ?: $a['subscription']->id <=> $b['subscription']->id,
        );

        return ['charges' => $charges, 'excluded_count' => $excluded];
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
    public function monthly(Scope $scope, int $months = self::MONTHS): array
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

        $current = $this->stats->combine($currentByCurrency);
        $previous = $this->stats->combine($previousByCurrency);

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
}
