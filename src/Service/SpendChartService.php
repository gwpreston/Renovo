<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Support\Clock;
use App\Support\DateFormatter;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * Twelve months of spend, as the payload a chart is drawn from.
 *
 * This was the dashboard's, and it is now the dashboard's and the analytics
 * screen's, which is the reason it is a class rather than a private method. The
 * two screens must agree about what March costs, and the way to make that a
 * fact about the code instead of a claim in a comment is for there to be one
 * piece of code that decides it. A second copy would agree on the day it was
 * written and drift afterwards.
 *
 * The design's chart was income against expenses. There is no income in a
 * subscription tracker, so this is spend over time: each renewal in the month
 * it actually falls, trials priced from their conversion date and scheduled
 * changes from theirs — which is what makes a yearly subscription a bill in
 * March rather than a twelfth of itself every month.
 *
 * Everything the browser draws is an integer number of minor units, and every
 * string it prints was formatted here by ICU. The client never divides a
 * currency by a hundred, so the question of a float amount reaching a chart
 * does not arise. The axis is pinned to ticks named here for the same reason:
 * an axis label is a money value, and money values are formatted in one place.
 *
 * **When a month cannot be combined, there is no chart.** A line drawn through
 * a missing total would slope towards it as though the month were cheap rather
 * than unknown — the one failure mode worth refusing outright. The caller says
 * which currencies have no rate and points at the Forecast page, which shows
 * those months per currency.
 *
 * **Two lines, and the gap between them is the point.** `minor` is every charge
 * the horizon holds. `committed_minor` is the same walk with the trials taken
 * out, so the distance between the two is what converting trials will add to a
 * month that is not paying for them yet. A trial is the one future cost a user
 * can still avoid, and a single line either hides it or overstates today.
 *
 * The split is made on `isTrial`, not on a charge's `reason`. A trial that
 * converts inside the horizon goes on renewing afterwards, and those later
 * charges are reasoned `renewal` like any other — filtering on the reason would
 * count the conversion as trial-driven and then quietly count the renewals it
 * caused as committed. Every charge a trial produces belongs to the trial.
 *
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-type ChartMonth array{
 *     key: string,
 *     label: string,
 *     minor: int,
 *     display: string,
 *     committed_minor: int,
 *     committed_display: string,
 *     trial_minor: int,
 *     trial_display: string,
 *     is_partial: bool
 * }
 * @phpstan-type ChartTick array{value: int, label: string}
 */
final class SpendChartService
{
    public function __construct(
        private readonly StatsService $stats,
        private readonly InstanceSettingsService $settings,
        private readonly MoneyFormatter $money,
        private readonly DateFormatter $dates,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The payload for one spend chart, JSON included.
     *
     * @param list<MonthTotals> $months
     * @return array<string, mixed>
     */
    public function fromMonths(array $months): array
    {
        $currency = $this->settings->baseCurrency();

        // One definition of "can this be combined", applied to every currency
        // the horizon contains at once. A currency without a rate makes its own
        // months null, so asking about the union asks about each of them.
        $union = [];
        foreach ($months as $month) {
            foreach ($month['by_currency'] as $code => $amount) {
                $union[(string) $code] = ($union[(string) $code] ?? 0) + $amount;
            }
        }

        $combined = $this->stats->combine($union);
        $drawable = $combined['unconvertible'] === [];

        // The horizon starts today, not on the first of the month, so unless
        // today *is* the first the opening bucket holds only the part of the
        // month still ahead. As bars that read as a cheap month; as a line it
        // reads as a fall, which is worse — so the month says it is partial
        // and the chart draws that segment differently.
        $partialIndex = $this->clock->today()->format('j') === '1' ? null : 0;

        $points = [];
        $max = 0;
        $peak = null;
        $hasTrials = false;

        foreach ($months as $index => $month) {
            $minor = $drawable ? (int) ($month['combined_minor'] ?? 0) : 0;
            $committed = $drawable ? $this->committedTotal($month['events']) : 0;
            $trial = $minor - $committed;

            if ($trial !== 0) {
                $hasTrials = true;
            }

            $points[] = [
                'key' => $month['month'],
                'label' => $this->dates->format(
                    new DateTimeImmutable($month['month'] . '-01'),
                    'MMM',
                ),
                'minor' => $minor,
                'display' => $this->money->formatMinor($minor, $currency),
                'committed_minor' => $committed,
                'committed_display' => $this->money->formatMinor($committed, $currency),
                'trial_minor' => $trial,
                'trial_display' => $this->money->formatMinor($trial, $currency),
                'is_partial' => $index === $partialIndex,
            ];

            // Ties go to the earlier month: the first time spending reaches its
            // high point is the month worth looking at. Measured on the total
            // rather than the committed line, because the peak of the chart is
            // the peak of its upper edge.
            if ($minor > $max) {
                $max = $minor;
                $peak = $index;
            }
        }

        $axisMax = $this->axisMax($max);

        $chart = [
            'months' => $points,
            'peak_index' => $max > 0 ? $peak : null,
            'axis_max' => $axisMax,
            'ticks' => $this->ticks($axisMax, $currency),
            'currency' => $currency,
            'unconvertible' => $combined['unconvertible'],
            'is_drawable' => $drawable,
            // False when no trial converts inside the horizon, which is the
            // common case. The second line would then sit exactly on the first,
            // and two lines saying one thing is noise — so the chart, the
            // legend and the table all drop to one series on this flag.
            'has_trials' => $hasTrials,
        ];

        // Encoded here rather than in the template, for the reason the
        // catalogue's own JSON is: this is written inside a <script> element,
        // and the tag-escaping flags are not a decision a template should be
        // making one copy of.
        $chart['json'] = json_encode(
            $chart,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        return $chart;
    }

    /**
     * One month's spend with the trials taken out, in the base currency.
     *
     * Summed from the charges the forecast already walked rather than by
     * walking it a second time: `monthly()` hands every event over, so the
     * committed line is a second reading of one forecast, not a second
     * forecast. Two walks would agree on the day this was written.
     *
     * Combined through `StatsService` for the same reason the total is —
     * one definition of what converting a currency means. The caller has
     * already established that every currency in the horizon has a rate, and
     * these charges are a subset of those, so the combination cannot fail
     * here if it succeeded there.
     *
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}> $events
     */
    private function committedTotal(array $events): int
    {
        $byCurrency = [];
        foreach ($events as $event) {
            if ($event['subscription']->isTrial) {
                continue;
            }

            $amount = $event['amount'];
            $byCurrency[$amount->currency] = ($byCurrency[$amount->currency] ?? 0) + $amount->amountMinor;
        }

        return $this->stats->combine($byCurrency)['amount_minor'] ?? 0;
    }

    /**
     * A round number at or above the busiest month, for the top of the axis.
     *
     * One, two or five times a power of ten — the steps a person would choose
     * — so the axis reads 0/£300/£600 rather than 0/£287.50/£575.
     */
    private function axisMax(int $max): int
    {
        if ($max <= 0) {
            return 0;
        }

        $magnitude = 10 ** max(0, (int) floor(log10($max)));

        foreach ([1, 2, 5, 10] as $step) {
            $candidate = $step * $magnitude;
            if ($candidate >= $max) {
                return (int) $candidate;
            }
        }

        return $max;
    }

    /**
     * The values the axis is labelled at, with their labels.
     *
     * Pinned rather than left to the chart library: the library would generate
     * tick values of its own and have to format them, which means formatting
     * money in the browser. Naming both halves here keeps every currency string
     * on the page coming out of the same ICU formatter.
     *
     * @return list<ChartTick>
     */
    private function ticks(int $axisMax, string $currency): array
    {
        if ($axisMax <= 0) {
            return [];
        }

        $values = [0, intdiv($axisMax, 2), $axisMax];

        return array_map(
            fn (int $value): array => [
                'value' => $value,
                'label' => $this->money->formatMinor($value, $currency),
            ],
            $values,
        );
    }
}
