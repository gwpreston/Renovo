<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Security\Scope;
use App\Support\MoneyFormatter;

/**
 * The analytics screen: the KPI row, the spending trajectory, the category
 * donut, year over year and the notable subscriptions.
 *
 * The third of these assemblers, after DashboardService and
 * SubscriptionScreenService, and held to the same rule: **not one figure here
 * is computed for this screen.** The KPIs are the statistics the page already
 * reported, the trajectory is the payload the dashboard's chart is drawn from,
 * the donut is the category breakdown the my-subscriptions widget draws as
 * bars, and year over year is the reconstruction `StatsService` performs. This
 * phase is how the numbers are shown, not what they are.
 *
 * The one thing it does decide is what "notable" means, because nothing
 * computed it before. See `notable()` for the ranking and what it leaves out.
 *
 * @phpstan-import-type Breakdown from CategoryBreakdownService
 * @phpstan-type NotableRow array{subscription: Subscription, monthly_minor: int, comparable_minor: int}
 */
final class AnalyticsScreenService
{
    public function __construct(
        private readonly StatsService $stats,
        private readonly ForecastService $forecast,
        private readonly SpendChartService $spendChart,
        private readonly CategoryBreakdownService $breakdown,
        private readonly SubscriptionService $subscriptions,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * Everything on the screen.
     *
     * The subscription set is handed back with the rest. The usage ranking
     * further down the page needs the same rows, and walking the household a
     * second time for them would be two answers to one question waiting to
     * differ — the arrangement the dashboard uses for its near-window query.
     *
     * @return array{
     *     kpis: array<string, mixed>,
     *     trajectory: array<string, mixed>,
     *     categories: array<string, mixed>,
     *     year_over_year: array<string, mixed>,
     *     notable: array{highest: NotableRow|null, lowest: NotableRow|null, excluded_count: int},
     *     subscriptions: list<Subscription>
     * }
     */
    public function overview(Scope $scope): array
    {
        // First, because it runs the catch-up: due price changes, ended trials
        // and overdue payment dates are brought up to date before anything
        // below reads a figure the next page load would correct.
        $stats = $this->stats->dashboard($scope);

        // The dashboard's call, argument for argument. A `$forUserId` here
        // would give this screen one member's share and the dashboard the
        // household's, and "the two agree by construction" would stop being
        // true the moment anybody had a split.
        $months = $this->forecast->monthly($scope, ForecastService::DEFAULT_MONTHS);

        $all = $this->subscriptions->allForStats($scope);
        $breakdown = $this->breakdown->fromStats($stats);

        return [
            'kpis' => $this->kpis($stats),
            'trajectory' => $this->spendChart->fromMonths($months),
            'categories' => $breakdown + ['donut' => $this->donut($breakdown)],
            'year_over_year' => $this->stats->yearOverYear($scope),
            'notable' => $this->notable($all),
            'subscriptions' => $all,
        ];
    }

    /**
     * Monthly spend, annual spend, active subscriptions.
     *
     * The two money figures are shaped for `partials/spend.twig`, which is the
     * per-currency rule written once: one line for one currency, a combined
     * total when every currency converts, subtotals and no total when one does
     * not. The design's single clean number is the common case rather than the
     * only one — a headline that silently dropped a currency would be a wrong
     * number, not a tidy one.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function kpis(array $stats): array
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
            'active_count' => $stats['active_count'],
        ];
    }

    /**
     * The donut's payload — or nothing, which is the interesting case.
     *
     * A donut implies one whole. Its centre is that whole stated as a number,
     * and its segments claim to be shares of it. When the categories span
     * currencies that cannot all be converted to one base there is no such
     * number, so there is no donut: the screen shows the per-currency figures
     * the breakdown produced instead. Degrading to the honest view is the
     * behaviour, not an edge case to paper over — a centre label reading a
     * total that omits a currency would be worse than no picture at all.
     *
     * Every string the browser prints was formatted here by ICU, as on the
     * spend chart. Nothing client-side divides a currency by a hundred.
     *
     * @param Breakdown $breakdown
     * @return array<string, mixed>|null
     */
    private function donut(array $breakdown): ?array
    {
        if (!$breakdown['is_combined'] || $breakdown['groups'] === []) {
            return null;
        }

        $group = $breakdown['groups'][0];
        $currency = $group['currency'];

        $slices = [];
        foreach ($group['rows'] as $row) {
            $slices[] = [
                'name' => $row['name'],
                'minor' => $row['amount_minor'],
                'display' => $this->money->formatMinor($row['amount_minor'], $currency),
                'percent' => $row['percent'],
                'is_other' => false,
            ];
        }

        // The tail is a segment like any other, and its name is the one string
        // in this payload that needs translating. It arrives on the canvas as
        // `data-other-label`, beside the `aria-label` already there, so the
        // catalogue stays the single source of it.
        if ($group['other'] !== null) {
            $slices[] = [
                'name' => null,
                'minor' => $group['other']['amount_minor'],
                'display' => $this->money->formatMinor($group['other']['amount_minor'], $currency),
                'percent' => $group['other']['percent'],
                'is_other' => true,
            ];
        }

        $donut = [
            'slices' => $slices,
            'currency' => $currency,
            'total_minor' => $group['total_minor'],
            'total_display' => $this->money->formatMinor($group['total_minor'], $currency),
        ];

        // Encoded here rather than in the template: this is written inside a
        // <script> element, and the tag-escaping flags are not a decision a
        // template should be making one copy of.
        $donut['json'] = json_encode(
            $donut,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        return $donut;
    }

    /**
     * The most and least expensive subscription.
     *
     * Nothing computed this before, so the ranking is stated rather than
     * implied. Three decisions, each of which follows a rule the rest of the
     * application already keeps:
     *
     * **It ranks on monthly-normalised cost.** Comparing a yearly subscription
     * with a weekly one at face value would make the yearly one look expensive
     * because of its cycle rather than its price. `monthlyMinor()` is the
     * normalisation the whole application compares costs with.
     *
     * **It ranks on the base currency and excludes what cannot reach it.** Two
     * amounts in currencies with no rate between them have no order, and
     * inventing one by comparing the digits would rank 900 JPY above 50 GBP.
     * A subscription whose currency has no rate is therefore left out and
     * counted, and the count is shown — the same answer `yearOverYear` gives to
     * the same kind of gap.
     *
     * **It is recurring subscriptions only.** `monthlyMinor()` is null for a
     * one-off or a lifetime purchase, which `StatsService` already keeps out of
     * the recurring totals for the reason that applies here: a lifetime licence
     * has no monthly cost to be the highest or lowest of.
     *
     * Each result is displayed in **its own currency** — the conversion decides
     * the order and nothing else, so nobody is shown a price they have never
     * been charged.
     *
     * @param list<Subscription> $all
     * @return array{highest: NotableRow|null, lowest: NotableRow|null, excluded_count: int}
     */
    private function notable(array $all): array
    {
        $base = $this->settings->baseCurrency();

        $ranked = [];
        $excluded = 0;

        foreach ($all as $subscription) {
            if (!$subscription->isActive) {
                continue;
            }

            $monthly = $subscription->monthlyMinor();
            if ($monthly === null) {
                continue;
            }

            $comparable = $this->rates->convertMinor($monthly, $subscription->price->currency, $base);
            if ($comparable === null) {
                $excluded++;
                continue;
            }

            $ranked[] = [
                'subscription' => $subscription,
                'monthly_minor' => $monthly,
                'comparable_minor' => $comparable,
            ];
        }

        if ($ranked === []) {
            return ['highest' => null, 'lowest' => null, 'excluded_count' => $excluded];
        }

        // Cheapest first, and alphabetically within a tie so that two
        // subscriptions costing the same do not swap places between page loads.
        usort($ranked, static fn (array $a, array $b): int => $a['comparable_minor'] <=> $b['comparable_minor']
            ?: strcmp($a['subscription']->name, $b['subscription']->name));

        $highest = $ranked[count($ranked) - 1];

        return [
            'highest' => $highest,
            // One subscription is both the most and the least expensive thing
            // in the household, and printing it twice says nothing the first
            // line did not.
            'lowest' => count($ranked) > 1 ? $ranked[0] : null,
            'excluded_count' => $excluded,
        ];
    }
}
