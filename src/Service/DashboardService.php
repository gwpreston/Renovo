<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Domain\SubscriptionFilter;
use App\Security\Scope;
use App\Support\Clock;
use App\Support\DateFormatter;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * The landing screen's tiles.
 *
 * Nothing here computes a figure of its own. Every number on the dashboard is
 * one an existing service already produces — the statistics, the forecast, the
 * budget projection, the subscription list — and this assembles them into the
 * shapes the tiles render. That is the whole point: the dashboard's twelve-month
 * chart is literally the call the Forecast page makes, so the two cannot show
 * different totals for the same data, and a figure fixed in one place is fixed
 * on both screens.
 *
 * **One near window, three consumers.** "Renewing soon" is fourteen days — the
 * window the cancel-by view already calls urgent — and the renewals metric, the
 * table's badge and the Expiring chip are all derived from the same query for
 * it. Given three separate definitions they would eventually disagree, and a
 * dashboard that contradicts itself is worse than one with fewer tiles.
 *
 * @phpstan-import-type BudgetProgress from BudgetService
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-type ChartMonth array{key: string, label: string, minor: int, display: string}
 * @phpstan-type ChartTick array{value: int, label: string}
 */
final class DashboardService
{
    /**
     * The near window, in days.
     *
     * The cancel-by view's urgent window, reused rather than re-chosen. It is
     * *renewals* inside it that this counts, not cancel-by deadlines: with a
     * month's notice period a deadline can be behind you while the renewal it
     * belongs to is weeks away, and a tile headed "renewing soon" must count
     * the renewals.
     */
    public const NEAR_WINDOW_DAYS = CancellationService::URGENT_DAYS;

    /** The views the table's chips offer. */
    public const VIEWS = ['active', 'all', 'expiring'];

    /** Enough of the list to be useful, not so much that it becomes the list. */
    private const RECENT_ROWS = 8;

    /** Distribution bars past this many are a legend, not a picture. */
    private const CATEGORY_BARS = 6;

    public function __construct(
        private readonly StatsService $stats,
        private readonly ForecastService $forecast,
        private readonly BudgetService $budgets,
        private readonly SubscriptionService $subscriptions,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly MoneyFormatter $money,
        private readonly DateFormatter $dates,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Everything above the table: the metric row, the chart and the usage
     * widget.
     *
     * The near-window query is handed back with them: the table needs the same
     * list for its badges and its Expiring chip, and walking it twice for one
     * page would be two answers to one question waiting to differ.
     *
     * @return array{
     *     stats: array<string, mixed>,
     *     metrics: array<string, mixed>,
     *     chart: array<string, mixed>,
     *     usage: array<string, mixed>,
     *     renewing_soon: list<Subscription>
     * }
     */
    public function overview(Scope $scope): array
    {
        // First, because it brings due price changes, ended trials and overdue
        // payment dates up to date; everything below is read afterwards so no
        // tile is computed from a figure the next page load would correct.
        $stats = $this->stats->dashboard($scope);

        $soon = $this->subscriptions->upcoming($scope, self::NEAR_WINDOW_DAYS);
        $months = $this->forecast->monthly($scope, ForecastService::DEFAULT_MONTHS);

        return [
            'stats' => $stats,
            'metrics' => $this->metrics($stats, $soon, $months),
            'chart' => $this->chart($months),
            'usage' => $this->usage($scope, $stats),
            'renewing_soon' => $soon,
        ];
    }

    /**
     * The four metric tiles.
     *
     * Four tiles, not one per currency. Monthly and yearly spend are a tile
     * each listing their per-currency subtotals, with the combined figure
     * alongside only when every currency in play converts — the rule the rest
     * of the application follows. A card may therefore be two lines tall, and
     * that is the correct answer rather than a compromise: a single big number
     * that silently omits a currency is a wrong number.
     *
     * The fourth tile is where the design put a virtual card. It holds the
     * active-subscription count and the next charge with its date, both of
     * which are true.
     *
     * @param array<string, mixed>   $stats
     * @param list<Subscription>     $soon
     * @param list<MonthTotals>      $months
     * @return array<string, mixed>
     */
    private function metrics(array $stats, array $soon, array $months): array
    {
        /** @var list<array{currency: string, monthly_minor: int, yearly_minor: int, count: int}> $recurring */
        $recurring = $stats['recurring'];

        return [
            'monthly' => [
                'totals' => array_map(
                    static fn (array $row): array => [
                        'currency' => $row['currency'],
                        'amount_minor' => $row['monthly_minor'],
                    ],
                    $recurring,
                ),
                'combined' => $stats['combined_monthly'],
            ],
            'yearly' => [
                'totals' => array_map(
                    static fn (array $row): array => [
                        'currency' => $row['currency'],
                        'amount_minor' => $row['yearly_minor'],
                    ],
                    $recurring,
                ),
                'combined' => $stats['combined_yearly'],
            ],
            'renewals' => [
                'count' => count($soon),
                'days' => self::NEAR_WINDOW_DAYS,
                'total' => $this->stats->sumByCurrency($soon),
            ],
            'portfolio' => [
                'active_count' => $stats['active_count'],
                'next' => $this->nextCharge($months),
            ],
            'one_off' => $stats['one_off'],
        ];
    }

    /**
     * The next charge due, whatever kind of charge it is.
     *
     * Taken from the forecast rather than from the next payment date, because
     * the forecast is the thing that knows a trial converting on Friday is a
     * charge and that a scheduled increase applies from its own date. The
     * charges are in date order, so the first event in the first month that has
     * one is the next thing to be paid for.
     *
     * @param list<MonthTotals> $months
     * @return array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}|null
     */
    private function nextCharge(array $months): ?array
    {
        foreach ($months as $month) {
            foreach ($month['events'] as $event) {
                return $event;
            }
        }

        return null;
    }

    /**
     * The twelve-month spend chart.
     *
     * The design's chart was income against expenses. There is no income in a
     * subscription tracker, so this is spend over time: each renewal in the
     * month it actually falls, trials priced from their conversion date and
     * scheduled changes from theirs — which is what makes a yearly subscription
     * a bill in March rather than a twelfth of itself every month.
     *
     * Everything the browser draws is an integer number of minor units, and
     * every string it prints was formatted here by ICU. The client never
     * divides a currency by a hundred, so the question of a float amount
     * reaching a chart does not arise. The axis is pinned to ticks named here
     * for the same reason: an axis label is a money value, and money values are
     * formatted in one place.
     *
     * **When a month cannot be combined, there is no chart.** A bar chart draws
     * a missing total as a short bar, which reads as a cheap month rather than
     * an unknown one — the one failure mode worth refusing outright. The tile
     * says which currencies have no rate and points at the Forecast page, which
     * shows those months per currency.
     *
     * @param list<MonthTotals> $months
     * @return array<string, mixed>
     */
    private function chart(array $months): array
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

        $points = [];
        $max = 0;
        $peak = null;

        foreach ($months as $index => $month) {
            $minor = $drawable ? (int) ($month['combined_minor'] ?? 0) : 0;

            $points[] = [
                'key' => $month['month'],
                'label' => $this->dates->format(
                    new DateTimeImmutable($month['month'] . '-01'),
                    'MMM',
                ),
                'minor' => $minor,
                'display' => $this->money->formatMinor($minor, $currency),
            ];

            // Ties go to the earlier month: the first time spending reaches its
            // high point is the month worth looking at.
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

    /**
     * The usage widget: this member's budget, and where the money goes.
     *
     * The design's "$1200 from $299 limit" was a made-up allowance. The real
     * version of it is a budget against the spend projected for it, which the
     * application already computes from the same forecast the chart draws — so
     * the widget and the bars above it are two readings of one number.
     *
     * @param array<string, mixed> $stats
     * @return array{budget: BudgetProgress|null, categories: array<string, mixed>}
     */
    private function usage(Scope $scope, array $stats): array
    {
        return [
            'budget' => $this->ownBudget($scope),
            'categories' => $this->categoryShares($stats),
        ];
    }

    /**
     * The signed-in member's own budget, or null if they have not set one.
     *
     * A budget measures one member's share, so in SHARED isolation — where an
     * Owner can see everybody's — theirs is the only one that belongs on their
     * dashboard. Somebody with several gets the one that covers the most
     * ground: the overall budget before a per-category one, the shorter period
     * before the longer, and the older before the newer so the choice is
     * stable rather than a matter of row order.
     *
     * @return BudgetProgress|null
     */
    private function ownBudget(Scope $scope): ?array
    {
        $own = array_values(array_filter(
            $this->budgets->progress($scope),
            static fn (array $row): bool => $row['budget']->ownerUserId === $scope->userId,
        ));

        if ($own === []) {
            return null;
        }

        usort($own, static function (array $a, array $b): int {
            return ($a['budget']->isOverall() ? 0 : 1) <=> ($b['budget']->isOverall() ? 0 : 1)
                ?: $a['budget']->period->months() <=> $b['budget']->period->months()
                ?: $a['budget']->id <=> $b['budget']->id;
        });

        return $own[0];
    }

    /**
     * Where the recurring spend goes, as a share of the monthly total.
     *
     * The rows are the ones the By category card shows, converted to the base
     * currency so that two categories in different currencies can be compared —
     * which is the entire claim a distribution bar makes. The bars are drawn
     * only when the combined monthly total exists, and when it does every
     * currency has a rate, so no category can be the one that fails to convert.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function categoryShares(array $stats): array
    {
        /** @var array{currency: string, amount_minor: int|null, unconvertible: list<string>} $combined */
        $combined = $stats['combined_monthly'];
        $currency = $combined['currency'];
        $total = $combined['amount_minor'];

        if ($total === null || $total <= 0) {
            return ['currency' => $currency, 'total_minor' => $total, 'rows' => [], 'other' => null];
        }

        /** @var list<array{name: string, currency: string, monthly_minor: int, count: int}> $byCategory */
        $byCategory = $stats['by_category'];

        $byName = [];
        foreach ($byCategory as $row) {
            $byName[$row['name']][$row['currency']] = ($byName[$row['name']][$row['currency']] ?? 0)
                + $row['monthly_minor'];
        }

        $rows = [];
        foreach ($byName as $name => $amounts) {
            $rows[] = [
                'name' => (string) $name,
                'amount_minor' => $this->rates->combine($amounts, $currency) ?? 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['amount_minor'] <=> $a['amount_minor']
            ?: strcmp($a['name'], $b['name']));

        $shown = array_slice($rows, 0, self::CATEGORY_BARS);
        $rest = array_slice($rows, self::CATEGORY_BARS);

        $withPercent = array_map(
            static fn (array $row): array => $row + [
                'percent' => Rounding::multiplyDivide($row['amount_minor'], 100, $total),
            ],
            $shown,
        );

        $otherMinor = array_sum(array_column($rest, 'amount_minor'));

        return [
            'currency' => $currency,
            'total_minor' => $total,
            'rows' => $withPercent,
            // Named rather than dropped: a bar chart that quietly leaves out
            // the tail would misstate every share drawn beside it.
            'other' => $rest === [] ? null : [
                'count' => count($rest),
                'amount_minor' => $otherMinor,
                'percent' => Rounding::multiplyDivide((int) $otherMinor, 100, $total),
            ],
        ];
    }

    /**
     * The table beneath: subscriptions, with a status computed from real state.
     *
     * Every row comes through the repository the list page uses, so the scoping
     * layer decides what is in it exactly as it does there — on an ISOLATED
     * instance, the member's own subscriptions plus anything they help pay for.
     * The chips are the list's own filter, not a new query path: All includes
     * the paused ones, Active is the default, and Expiring is the near-window
     * query the renewals metric is counted from.
     *
     * @param list<Subscription>|null $soon The near-window renewals, when the
     *                                        caller has already asked for them.
     * @return array{
     *     view: string,
     *     views: list<string>,
     *     rows: list<Subscription>,
     *     statuses: array<int, string>
     * }
     */
    public function recent(Scope $scope, string $view = 'active', ?array $soon = null): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : 'active';

        $soon ??= $this->subscriptions->upcoming($scope, self::NEAR_WINDOW_DAYS);
        $soonIds = [];
        foreach ($soon as $subscription) {
            $soonIds[$subscription->id] = true;
        }

        $rows = match ($view) {
            'expiring' => array_slice($soon, 0, self::RECENT_ROWS),
            'all' => $this->subscriptions->list($scope, new SubscriptionFilter(
                includeInactive: true,
                perPage: self::RECENT_ROWS,
            )),
            default => $this->subscriptions->list($scope, new SubscriptionFilter(
                perPage: self::RECENT_ROWS,
            )),
        };

        return [
            'view' => $view,
            'views' => self::VIEWS,
            'rows' => $rows,
            'statuses' => $this->statuses($rows, $soonIds),
        ];
    }

    /**
     * Each row's badge, from what the row actually says.
     *
     * A trial is a trial until the date it converts on; a subscription renewing
     * inside the near window is renewing soon; a paused one says so. The
     * remainder are simply active, which is the only state a badge here can
     * claim without asking another question.
     *
     * @param list<Subscription>  $rows
     * @param array<int, true>    $soonIds
     * @return array<int, string>
     */
    private function statuses(array $rows, array $soonIds): array
    {
        $today = $this->clock->today();
        $statuses = [];

        foreach ($rows as $subscription) {
            $statuses[$subscription->id] = match (true) {
                !$subscription->isActive => 'paused',
                $subscription->isTrial && !$subscription->trialHasEndedBy($today) => 'trial',
                isset($soonIds[$subscription->id]) => 'renewing',
                default => 'active',
            };
        }

        return $statuses;
    }
}
