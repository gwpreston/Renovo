<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Security\Scope;
use App\Support\MoneyFormatter;

/**
 * The analytics screen: the KPI row, the twelve months behind and the twelve
 * ahead, the category donut, year over year and the notable subscriptions.
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
 * Phase 13 put one exception beside that claim, and it is worth naming rather
 * than leaving the paragraph above quietly untrue: the insight card is a
 * genuinely new observation, not a restyle of an old one. It is assembled here
 * because it reads the rows this screen has already loaded, but the rules and
 * the figures are `SpendInsightService`'s.
 *
 * @phpstan-import-type Breakdown from CategoryBreakdownService
 * @phpstan-import-type Insight from SpendInsightService
 * @phpstan-import-type ValueSignal from UsageService
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
        private readonly UsageService $usage,
        private readonly SpendInsightService $insights,
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
     * **Known cost: four walks of the household for one page.** The statistics
     * read every subscription, this reads them again because `notable` and the
     * usage ranking both want the rows, and `yearOverYear` and the history
     * chart each read them again with their own argument and their own window.
     * Sharing one read would mean changing what `StatsService` hands back
     * rather than what it computes, which is a change to a service three
     * screens depend on and not a restyle's to make. Worth fixing when
     * something else touches it; stated here so it is a known cost rather than
     * a surprise.
     *
     * @return array{
     *     kpis: array<string, mixed>,
     *     trajectory: array<string, mixed>,
     *     history: array<string, mixed>,
     *     categories: array<string, mixed>,
     *     payment_methods: array<string, mixed>,
     *     year_over_year: array<string, mixed>,
     *     notable: array{highest: NotableRow|null, lowest: NotableRow|null, excluded_count: int},
     *     insights: list<Insight>,
     *     value_signals: list<ValueSignal>,
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
        $byMethod = $this->breakdown->fromStats($stats, CategoryBreakdownService::BY_PAYMENT_METHOD);

        // Computed once and handed to both the insight rules and the ranking
        // at the foot of the page. It is a pure read of the rows above, but two
        // callers computing it separately is the arrangement this whole class
        // exists to avoid.
        $valueSignals = $this->usage->valueSignals($all);

        return [
            'kpis' => $this->kpis($stats),
            'trajectory' => $this->spendChart->fromMonths($months),
            // What the trajectory is a continuation of. The same payload
            // builder as the trajectory and the same reconstruction the
            // year-over-year card is totalled from, so one walk of the
            // household answers both — over twelve calendar months here and a
            // rolling year there, which is why the totals are close rather
            // than equal.
            'history' => $this->spendChart->fromHistory($this->stats->monthlyHistory($scope)),
            'categories' => $breakdown + ['donut' => $this->donut($breakdown)],
            // The same breakdown and the same degrade, grouped by what each
            // subscription is paid with rather than what it is for.
            'payment_methods' => $byMethod + ['donut' => $this->donut($byMethod)],
            'year_over_year' => $this->stats->yearOverYear($scope),
            'notable' => $this->notable($all),
            // After the catch-up, like everything else here: insights read
            // prices and trial states that are already up to date, so the card
            // cannot contradict the KPI row above it.
            'insights' => $this->insights->insights($scope, $all, $valueSignals),
            'value_signals' => $valueSignals,
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
                // A payment method's own colour when it has one; null takes
                // the palette. Categories carry none, so their donut is as it
                // was.
                'colour' => $row['colour'],
                // The subscriptions with no payment method, which the
                // statistics name with the empty string. Its label is the
                // canvas's `data-unassigned-label`, like the tail's.
                'is_unassigned' => $row['name'] === '',
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
                'colour' => null,
                'is_unassigned' => false,
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
     * **And a running trial is not ranked either.** Its price is zero until it
     * converts, so it would take the "least expensive" line every time and
     * report £0.00 — which is what the trial costs today and not what it costs.
     * The trials section says what each one will convert to; this card would be
     * contradicting it. Pricing it at `priceAfterConversion()` instead was the
     * alternative and is worse: the same subscription would then be one figure
     * here and another in the KPI row above, on one screen.
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

            // Free today, priced later: neither the cheapest thing in the
            // household nor a comparison anybody can act on.
            if ($subscription->isTrial) {
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
