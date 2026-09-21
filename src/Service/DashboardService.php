<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Security\Scope;
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
 * **One near window.** "Renewing soon" is fourteen days — the window the
 * cancel-by view already calls urgent — and the renewals metric is counted
 * from it rather than from a definition of its own. Given two definitions they
 * would eventually disagree, and a dashboard that contradicts itself is worse
 * than one with fewer tiles.
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

    /** Distribution bars past this many are a legend, not a picture. */
    private const CATEGORY_BARS = 6;

    public function __construct(
        private readonly StatsService $stats,
        private readonly ForecastService $forecast,
        private readonly BudgetService $budgets,
        private readonly SubscriptionService $subscriptions,
        private readonly ExchangeRateService $rates,
        private readonly SpendChartService $spendChart,
    ) {
    }

    /**
     * The whole screen: the metric row, the chart and the usage widget.
     *
     * @return array{
     *     stats: array<string, mixed>,
     *     metrics: array<string, mixed>,
     *     chart: array<string, mixed>,
     *     usage: array<string, mixed>
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
}
