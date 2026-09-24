<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Support\Clock;
use App\Support\CssPercent;
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
 * **Two windows, one builder.** `fromMonths()` draws the twelve months ahead
 * and `fromHistory()` the twelve behind, and they differ in exactly two ways —
 * which end of the row is a part month, and whether trials are worth a second
 * line — both of which are arguments to the one private builder. The axis, the
 * ticks, the peak and every formatted string are therefore decided in one
 * place, so the past and the future are drawn to the same rules and a reader
 * comparing them is comparing like with like.
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
     * The payload for the forecast chart: the twelve months ahead.
     *
     * @param list<MonthTotals> $months
     * @return array<string, mixed>
     */
    public function fromMonths(array $months): array
    {
        // The horizon starts today, so unless today *is* the first of the
        // month the opening bucket holds only the part of the month still
        // ahead.
        $partial = $this->clock->today()->format('j') === '1' ? null : 0;

        return $this->build($months, $months === [] ? null : $partial, true);
    }

    /**
     * The payload for the history chart: the twelve months behind.
     *
     * The same picture drawn from the other direction, and deliberately the
     * same builder — a second one would agree about the axis on the day it was
     * written and drift afterwards. Two things differ, and both are arguments
     * rather than settings:
     *
     * **The partial month is the last, not the first.** The window ends today,
     * so the closing bucket holds only the part of this month that has already
     * happened. Reusing the forecast's rule would dash the segment leaving the
     * *oldest* month and call a month that completed a year ago incomplete.
     *
     * **There is no trial line.** "What converting trials will add" is a claim
     * about the future; a trial that ran last spring either converted, in which
     * case the charges it produced are in these months as themselves, or it did
     * not, in which case it cost nothing. So the committed line sits exactly on
     * the total, `has_trials` stays false, and the second series, the legend
     * and the third column of the table all drop out on their own.
     *
     * @param list<MonthTotals> $months
     * @return array<string, mixed>
     */
    public function fromHistory(array $months): array
    {
        $today = $this->clock->today();

        // The mirror of the forecast's rule: complete only when today is the
        // last day of its month, because then there is no rest of the month
        // left to come.
        $partial = $today->format('j') === $today->format('t') ? null : count($months) - 1;

        return $this->build($months, $months === [] ? null : $partial, false);
    }

    /**
     * The Overview dashboard's bar chart: the months behind, this month, and
     * the months ahead.
     *
     * `$past` is the reconstruction ending yesterday, its last bucket being
     * this month so far; `$future` is the forecast from today, its first bucket
     * being the rest of this month. Past and future therefore meet at today
     * without sharing a charge, and this month is one bar in two parts —
     * already charged beneath, still due above — rather than two bars or a
     * part month drawn as a dip. Every other bar is either past or forecast,
     * and the forecast ones are exactly the Forecast page's months.
     *
     * The budget line, when there is one, is the household's monthly overall
     * limit already converted into the base currency by the caller, and the
     * axis is tall enough to show it. Heights come from `CssPercent`, worked
     * out on minor units, so nothing here divides money as a float.
     *
     * @param list<MonthTotals> $past   Ending with this month so far.
     * @param list<MonthTotals> $future Beginning with the rest of this month.
     * @return array<string, mixed>
     */
    public function monthBars(array $past, array $future, ?int $budgetMinor): array
    {
        $currency = $this->settings->baseCurrency();

        $union = [];
        foreach (array_merge($past, $future) as $month) {
            foreach ($month['by_currency'] as $code => $amount) {
                $union[(string) $code] = ($union[(string) $code] ?? 0) + $amount;
            }
        }

        $combined = $this->stats->combine($union);
        $drawable = $combined['unconvertible'] === [];

        $current = $past === [] ? null : $past[count($past) - 1];
        $rows = [];

        foreach (array_slice($past, 0, max(0, count($past) - 1)) as $month) {
            $rows[] = ['month' => $month['month'], 'kind' => 'past', 'charged' => $month, 'due' => null];
        }
        if ($current !== null) {
            $rows[] = [
                'month' => $current['month'],
                'kind' => 'current',
                'charged' => $current,
                'due' => $future[0] ?? null,
            ];
        }
        foreach (array_slice($future, 1) as $month) {
            $rows[] = ['month' => $month['month'], 'kind' => 'future', 'charged' => null, 'due' => $month];
        }

        $bars = [];
        $max = 0;
        $busiest = null;
        foreach ($rows as $index => $row) {
            $charged = $drawable && $row['charged'] !== null ? (int) ($row['charged']['combined_minor'] ?? 0) : 0;
            $due = $drawable && $row['due'] !== null ? (int) ($row['due']['combined_minor'] ?? 0) : 0;
            $total = $charged + $due;

            $bars[] = [
                'key' => $row['month'],
                'label' => $this->dates->format(new DateTimeImmutable($row['month'] . '-01'), 'MMM'),
                'long_label' => $this->dates->format(new DateTimeImmutable($row['month'] . '-01'), 'MMMM y'),
                'kind' => $row['kind'],
                'charged_minor' => $charged,
                'due_minor' => $due,
                'total_minor' => $total,
                'charged_display' => $this->money->formatMinor($charged, $currency),
                'due_display' => $this->money->formatMinor($due, $currency),
                'total_display' => $this->money->formatMinor($total, $currency),
            ];

            // Ties go to the earlier month, as on the line charts.
            if ($total > $max) {
                $max = $total;
                $busiest = $index;
            }
        }

        $axisMax = $this->axisMax(max($max, $budgetMinor ?? 0));

        foreach ($bars as $index => $bar) {
            $bars[$index]['charged_height'] = CssPercent::of($bar['charged_minor'], $axisMax);
            $bars[$index]['due_height'] = CssPercent::of($bar['due_minor'], $axisMax);
            $bars[$index]['is_busiest'] = $index === $busiest && $max > 0;
        }

        return [
            'bars' => $bars,
            'busiest_index' => $max > 0 ? $busiest : null,
            'axis_max' => $axisMax,
            'ticks' => $this->ticks($axisMax, $currency),
            'budget_minor' => $budgetMinor,
            'budget_display' => $budgetMinor === null ? null : $this->money->formatMinor($budgetMinor, $currency),
            'budget_height' => $budgetMinor === null ? null : CssPercent::of($budgetMinor, $axisMax),
            'currency' => $currency,
            'unconvertible' => $combined['unconvertible'],
            'is_drawable' => $drawable,
        ];
    }

    /**
     * One chart's payload, JSON included.
     *
     * @param list<MonthTotals> $months
     * @param int|null          $partialIndex Which bucket covers part of a month, if any.
     * @param bool              $splitTrials  Whether to draw the committed line under the total.
     * @return array<string, mixed>
     */
    private function build(array $months, ?int $partialIndex, bool $splitTrials): array
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

        // A bucket that covers part of a month reads as a cheap month, which
        // is worse as a line than as a bar: it reads as a fall. So the month
        // says it is partial, the chart draws the segment beside it dashed and
        // the figures carry a note. Which end it falls at is the caller's to
        // say — it is the opening month of a forecast and the closing month of
        // a history.
        $points = [];
        $max = 0;
        $peak = null;
        $hasTrials = false;

        foreach ($months as $index => $month) {
            $minor = $drawable ? (int) ($month['combined_minor'] ?? 0) : 0;
            $committed = $drawable && $splitTrials ? $this->committedTotal($month['events']) : $minor;
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
            // Named rather than left to be found: the template's footnote and
            // the browser's dashed segment both need to know which bucket it
            // is, and looking for the first month flagged partial only
            // happens to be right for a chart whose partial month is first.
            'partial_index' => $partialIndex,
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
