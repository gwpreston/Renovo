<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\SubscriptionFilter;
use App\Security\Scope;
use App\Support\Clock;
use App\Support\Distribution;
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
        private readonly SpendChartService $spendChart,
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
     * Built by `SpendChartService`, which the analytics screen's spending
     * trajectory also calls with the same months. That is what makes "the two
     * screens agree by construction" true of the code rather than of a comment:
     * there is one payload builder, so there is one answer about March.
     *
     * @param list<MonthTotals> $months
     * @return array<string, mixed>
     */
    private function chart(array $months): array
    {
        return $this->spendChart->fromMonths($months);
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

        // Ordering, the tail and the percentages are shared with the
        // my-subscriptions widget, which draws the same bars against a
        // denominator of its own choosing.
        return Distribution::bars($rows, $total, self::CATEGORY_BARS) + [
            'currency' => $currency,
            'total_minor' => $total,
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
