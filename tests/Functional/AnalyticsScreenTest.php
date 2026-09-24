<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\ForecastService;
use App\Service\PriceHistoryService;
use App\Service\SpendHistoryService;
use App\Service\StatsService;
use App\Support\MoneyFormatter;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The analytics screen, rendered through the real application.
 *
 * The claim this phase makes is that nothing on the screen is computed for it:
 * the KPIs are the statistics service's own figures, the trajectory is the
 * dashboard's chart payload, the donut is the breakdown the my-subscriptions
 * widget draws as bars. So these assert the *binding* rather than the
 * arithmetic — a figure is checked against the service that produced it, not
 * against a number written down here.
 *
 * Every assertion is made inside the section it is about. An amount appears in
 * several places on this page and a loose match would pass whatever the
 * sections did.
 */
final class AnalyticsScreenTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $viewerId;
    private int $householdId;

    private int $hostingId;
    private int $trialId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $container = $this->container();

        $settings = $container->get(\App\Service\InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        // Nothing may reach a rate provider from a test; the cache below is
        // what any combined figure is computed from.
        $settings->markRatesAttempted(new DateTimeImmutable());

        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);

        // £9.99 a month, and the cheapest recurring thing in the household.
        $subscriptions->create($owner, [
            'name' => 'Streaming',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+3 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // £120 a year: the most expensive by face value and *not* the most
        // expensive per month, which is what the normalisation is for.
        $this->hostingId = $subscriptions->create($owner, [
            'name' => 'Hosting',
            'price_minor' => 12000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // €40 a month — £20 at the fixture's rate, so the dearest per month
        // once converted, and the row that proves the ranking is not done on
        // the digits.
        $subscriptions->create($owner, [
            'name' => 'European thing',
            'price_minor' => 4000,
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+40 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // A lifetime purchase has no monthly cost, so it is not the most or the
        // least expensive thing per month — it is not in the ranking at all.
        $subscriptions->create($owner, [
            'name' => 'Lifetime licence',
            'price_minor' => 50000,
            'currency' => 'GBP',
            'subscription_type' => 'lifetime',
            'start_date' => '2025-06-01',
            'is_active' => true,
        ], []);

        // Free until it converts. Its price today is zero, which would win
        // "least expensive" outright and print a figure the trials section
        // deliberately refuses to print.
        $this->trialId = $subscriptions->create($owner, [
            'name' => 'Trial plan',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => (new DateTimeImmutable('+20 days'))->format('Y-m-d'),
            'converts_to_price_minor' => 1299,
            'is_active' => true,
        ], []);
    }

    public function testTheKpiRowShowsTheFiguresTheStatisticsServiceComputed(): void
    {
        $container = $this->container();
        $stats = $container->get(StatsService::class)->dashboard($this->scopeFor($this->ownerId));
        $money = $container->get(MoneyFormatter::class);

        $kpis = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-kpis');

        // The count is the application's own, not a number this test remembers.
        self::assertStringContainsString('>' . $stats['active_count'] . '<', $kpis);

        // Both currencies convert, so monthly and annual are each the combined
        // figure — the same rule, and the same partial, as the dashboard.
        $monthly = $stats['combined_monthly']['amount_minor'];
        $yearly = $stats['combined_yearly']['amount_minor'];
        self::assertNotNull($monthly, 'both currencies convert, so there should be a combined total');
        self::assertNotNull($yearly);

        self::assertStringContainsString($money->formatMinor($monthly, 'GBP'), $kpis);
        self::assertStringContainsString($money->formatMinor($yearly, 'GBP'), $kpis);
    }

    /**
     * The year behind is the shared reconstruction, not a second answer to the
     * same question. `SpendHistoryService` is what the dashboard's past bars
     * and year-over-year are read from too, so a payload that differed would
     * mean two walks of the household had been allowed to disagree about a
     * month that has already happened.
     */
    public function testTheHistoryIsTheSharedReconstruction(): void
    {
        $analytics = $this->payload(
            $this->body($this->get('/stats', $this->ownerId)),
            'analytics-history-data',
        );
        $months = $this->container()->get(SpendHistoryService::class)->monthly($this->scopeFor($this->ownerId));

        self::assertCount(12, $analytics['months'] ?? []);
        foreach ($months as $index => $month) {
            self::assertSame($month['month'], $analytics['months'][$index]['key']);
            self::assertSame($month['combined_minor'], $analytics['months'][$index]['minor']);
        }
    }

    /**
     * The two charts on this screen are the two halves of one window, and the
     * page has to be able to tell them apart: each canvas reaches its payload
     * by id, so two charts sharing one would draw the same picture twice.
     *
     * The part month is the assertion that says which is which. A forecast's
     * is its opening bucket and a history's its closing one, so this fails if
     * either chart is ever handed the other's rule.
     */
    public function testTheTwoChartsAreTwoChartsAndLookOppositeWays(): void
    {
        $body = $this->body($this->get('/stats', $this->ownerId));

        $history = $this->payload($body, 'analytics-history-data');
        $trajectory = $this->payload($body, 'analytics-trajectory-data');

        self::assertNotSame($history['months'], $trajectory['months']);

        // Null on the first of the month, when neither end is partial —
        // asserted as "not the same end" rather than as two fixed indices so
        // the test does not depend on the day it runs.
        if ($history['partial_index'] !== null || $trajectory['partial_index'] !== null) {
            self::assertSame(11, $history['partial_index']);
            self::assertSame(0, $trajectory['partial_index']);
        }
    }

    /**
     * The trajectory is the forecast, asserted against the forecast.
     *
     * It used to be asserted against the dashboard's copy of the same chart,
     * which was the stronger claim while both screens drew it. The dashboard
     * draws the year behind only now, so the comparison that is left is the
     * one that always mattered: this picture is `ForecastService`'s own
     * months, not a second opinion assembled for the page.
     */
    public function testTheTrajectoryIsTheSameFiguresTheForecastProduces(): void
    {
        $months = $this->container()->get(ForecastService::class)->monthly(
            $this->scopeFor($this->ownerId),
            ForecastService::DEFAULT_MONTHS,
        );

        $payload = $this->payload(
            $this->body($this->get('/stats', $this->ownerId)),
            'analytics-trajectory-data',
        );

        self::assertCount(count($months), $payload['months']);

        foreach ($months as $index => $month) {
            self::assertSame(
                $month['combined_minor'],
                $payload['months'][$index]['minor'],
                'month ' . $month['month'] . ' disagrees with the forecast',
            );
        }

        // Integers all the way to the browser: the only decimal point in the
        // payload belongs to a string ICU formatted.
        foreach ($payload['months'] as $month) {
            self::assertIsInt($month['minor']);
        }
        foreach ($payload['ticks'] as $tick) {
            self::assertIsInt($tick['value']);
            self::assertIsString($tick['label']);
        }
    }

    /**
     * The second line, and the one mistake it exists to avoid.
     *
     * The trajectory draws spend twice: every charge the horizon holds, and
     * the same months with the trials taken out. The gap between them is what
     * converting trials will add, and the fixture's trial converts inside the
     * horizon and then renews monthly for the rest of it.
     *
     * So the gap is not one month wide. **That is the whole assertion.** A
     * charge carries a `reason`, and only the conversion itself is reasoned
     * `trial_conversion` — every renewal it causes afterwards is an ordinary
     * `renewal`. Splitting on the reason would therefore show the conversion
     * as trial-driven and then quietly count the ten renewals it caused as
     * spend already committed, which is the opposite of what the line means.
     * Splitting on `isTrial`, as the payload does, takes all of them out.
     *
     * This and the four tests below were `DashboardTest`'s while the dashboard
     * was where this chart was drawn. The chart moved; they moved with it.
     */
    public function testTheCommittedLineExcludesEveryChargeATrialCausesNotJustItsConversion(): void
    {
        $payload = $this->trajectory();

        self::assertTrue($payload['has_trials'], 'the fixture has a trial converting inside the horizon');

        $withAGap = array_filter($payload['months'], static fn (array $month): bool => $month['trial_minor'] !== 0);

        self::assertGreaterThanOrEqual(
            10,
            count($withAGap),
            'the conversion and the renewals it causes must all sit outside the committed line; '
            . 'exactly one month here would mean the split was made on the charge reason',
        );

        // By the far end of the horizon the trial has long since converted and
        // is renewing at its converts-to price, in the base currency.
        $last = $payload['months'][count($payload['months']) - 1];
        self::assertSame(1299, $last['trial_minor']);
        self::assertSame($last['minor'] - 1299, $last['committed_minor']);
    }

    /**
     * The committed line is the total with things removed, never added to.
     *
     * Asserted across every month rather than at a point, because the two
     * figures are combined from different subsets of the same charges and a
     * currency conversion sits between the charges and the line. If the
     * subtraction ever came out the other way, the chart would fill its band
     * downwards and describe trials as a saving.
     */
    public function testTheCommittedLineIsNeverAboveTheTotalAndTheTwoAlwaysReconcile(): void
    {
        foreach ($this->trajectory()['months'] as $month) {
            self::assertIsInt($month['committed_minor']);
            self::assertIsInt($month['trial_minor']);
            self::assertLessThanOrEqual(
                $month['minor'],
                $month['committed_minor'],
                'month ' . $month['key'] . ' claims trials make it cheaper',
            );
            self::assertSame(
                $month['minor'],
                $month['committed_minor'] + $month['trial_minor'],
                'month ' . $month['key'] . ' does not add up',
            );
        }
    }

    /**
     * A subscription that is not a trial belongs to both lines, at whatever
     * price will actually be charged.
     *
     * The committed line is not "today's price extended across the horizon" —
     * it is the same forecast with one class of charge removed, so a scheduled
     * increase has to reach it exactly as it reaches the total. A committed
     * line that ignored the increase would sit below the total for a reason
     * that has nothing to do with trials, and the band would blame them for it.
     *
     * Measured as the difference the schedule makes to one month, rather than
     * the difference between two months: the fixture has other subscriptions
     * falling in some months and not others, so month-to-month is not a
     * reading of this subscription at all.
     */
    public function testAScheduledIncreaseOnANonTrialReachesBothLines(): void
    {
        $scope = $this->scopeFor($this->ownerId);
        $effective = new DateTimeImmutable('+100 days');
        // A month clear of the change, so the month being read holds one
        // charge at the new price rather than straddling the two.
        $settled = $effective->modify('+1 month')->format('Y-m');

        $id = (new SubscriptionRepository($this->db))->create($scope, [
            'name' => 'Dearer soon',
            'price_minor' => 1000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $history = $this->container()->get(PriceHistoryService::class);
        $history->recordInitialPrice(
            $scope,
            $id,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->ownerId,
        );

        $before = $this->trajectoryMonthsByKey();

        $history->schedule($scope, $id, [
            'price' => '25.00',
            'currency' => 'GBP',
            'effective_from' => $effective->format('Y-m-d'),
        ]);

        $after = $this->trajectoryMonthsByKey();

        self::assertArrayHasKey($settled, $before);
        self::assertArrayHasKey($settled, $after);

        // The same 1500 minor units on each line: an increase on something
        // that is not a trial is committed spend.
        self::assertSame(1500, $after[$settled]['minor'] - $before[$settled]['minor']);
        self::assertSame(
            1500,
            $after[$settled]['committed_minor'] - $before[$settled]['committed_minor'],
            'the increase reached the total but not the committed line',
        );
        self::assertSame(
            $before[$settled]['trial_minor'],
            $after[$settled]['trial_minor'],
            'a price change on something that is not a trial moved the trial band',
        );
    }

    /**
     * The card that describes the chart says what the chart actually shows.
     *
     * The payload is JSON in a script tag and the picture is a canvas, so
     * neither is what a reader without JavaScript — or with a screen reader —
     * is given. That reader gets the legend and the table, and this is the
     * only thing asserting they name both series.
     *
     * Read inside the trajectory's own section: this screen draws two charts
     * and the history beside it has a table of its own.
     */
    public function testBothSeriesAreNamedInTheLegendAndTheTable(): void
    {
        $body = $this->body($this->get('/stats', $this->ownerId));
        $section = $this->section($body, 'analytics-trajectory');

        self::assertStringContainsString('chart-legend', $section);
        self::assertStringContainsString('Including trial conversions', $section);
        self::assertStringContainsString('Excluding trial conversions', $section);
        self::assertSame(2, substr_count($section, '<th scope="col" class="numeric">'));

        // The description the canvas carries, which is the chart for a reader
        // who is given one sentence rather than a picture.
        self::assertStringContainsString(
            'one line including trial conversions and one excluding them',
            $section,
        );
    }

    /**
     * The common case: no trial converting inside the horizon.
     *
     * The second line would then sit exactly on the first, so it is not drawn,
     * and everything that describes it has to disappear with it — the legend,
     * the table's second column, and the sentence in the canvas's own label
     * promising two lines. A description of a chart that is not there is worse
     * than no description, because it is the only chart some readers get.
     */
    public function testWithNoTrialInTheHorizonTheTrajectoryDropsToASingleSeries(): void
    {
        (new SubscriptionRepository($this->db))->delete($this->scopeFor($this->ownerId), $this->trialId);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $payload = $this->payload($body, 'analytics-trajectory-data');

        self::assertFalse($payload['has_trials']);

        foreach ($payload['months'] as $month) {
            self::assertSame($month['minor'], $month['committed_minor'], 'month ' . $month['key']);
            self::assertSame(0, $month['trial_minor'], 'month ' . $month['key']);
        }

        // Scoped to the section for the reason the test above is, and for one
        // more: the layout writes the browser's whole catalogue into every
        // page, so a bare string match would find the second series' label
        // whatever the chart drew.
        $section = $this->section($body, 'analytics-trajectory');

        self::assertStringNotContainsString('chart-legend', $section);
        self::assertStringNotContainsString('Excluding trial conversions', $section);
        self::assertSame(1, substr_count($section, '<th scope="col" class="numeric">'));
        self::assertStringNotContainsString(
            'one line including trial conversions and one excluding them',
            $section,
            'the canvas still describes a second line that is not drawn',
        );
    }

    /**
     * A month that cannot be combined is not drawn as a short bar.
     *
     * The other half of `DashboardTest`'s old missing-rate test: the metric
     * row's combined total is still asserted there, and the chart it used to
     * withhold is here.
     */
    public function testAMissingRateWithholdsTheTrajectoryRatherThanDrawingAGap(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Unconvertible',
            'price_minor' => 5000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'is_active' => true,
        ], []);

        $section = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-trajectory');

        self::assertStringNotContainsString('analytics-trajectory-data', $section);
        self::assertStringContainsString('XOF', $section);
        self::assertStringContainsString('no exchange rate is available', $section);
    }

    public function testTheDonutsCentreIsTheTotalItsSegmentsAreSharesOf(): void
    {
        $container = $this->container();
        $stats = $container->get(StatsService::class)->dashboard($this->scopeFor($this->ownerId));
        $total = $stats['combined_monthly']['amount_minor'];
        self::assertNotNull($total);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $card = $this->section($body, 'analytics-categories');

        // The centre label is the combined monthly total, stated — the claim
        // the picture makes.
        self::assertStringContainsString(
            $container->get(MoneyFormatter::class)->formatMinor($total, 'GBP'),
            $card,
        );

        // And the payload the browser draws from agrees with it, so the
        // picture and the label cannot come apart.
        $donut = $this->payload($body, 'analytics-donut-data');
        self::assertSame($total, $donut['total_minor']);
        self::assertSame(
            $total,
            (int) array_sum(array_column($donut['slices'], 'minor')),
            'the segments should add up to the whole the centre claims',
        );
    }

    /**
     * The behaviour, not an edge case to paper over.
     *
     * A donut implies one whole. With a currency that has no rate there is no
     * such number, so the screen shows the per-currency figures instead of a
     * donut whose centre would be a total that does not exist.
     */
    public function testTheDonutDegradesToPerCurrencyFiguresRatherThanAFalseWhole(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Unconvertible',
            'price_minor' => 5000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'is_active' => true,
        ], []);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $card = $this->section($body, 'analytics-categories');

        // No chart at all, and no payload for one to be drawn from.
        self::assertStringNotContainsString('data-chart="donut"', $card);
        self::assertStringNotContainsString('analytics-donut-data', $body);

        // The honest view instead: a group per currency, each against its own
        // total, with the missing rate named.
        self::assertStringContainsString('XOF', $card);
        self::assertStringContainsString('>GBP<', $card);
        self::assertStringContainsString('meter-fill', $card);
    }

    /**
     * The same breakdown grouped by what each subscription is paid with: a
     * method's segment is the sum of its subscriptions' monthly costs, the
     * unassigned ones are named rather than dropped, and a method's own colour
     * rides through to its segment.
     */
    public function testThePaymentMethodDonutSumsEachMethodAndNamesTheUnassigned(): void
    {
        $owner = $this->scopeFor($this->ownerId);
        $methods = new PaymentMethodRepository($this->db);
        $card = $methods->create($owner, 'Joint card', '#123456', 'payment-card', null);
        $cash = $methods->create($owner, 'Cash', null, 'payment-cash', null);

        // £9.99 a month and £120 a year: £19.99 a month between them.
        $this->assignByName('Streaming', $card);
        $this->assignByName('Hosting', $card);
        // A lifetime purchase is not recurring spend, whatever pays for it.
        $this->assignByName('Lifetime licence', $cash);

        $container = $this->container();
        $stats = $container->get(StatsService::class)->dashboard($owner);
        $total = $stats['combined_monthly']['amount_minor'];
        self::assertNotNull($total);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $donut = $this->payload($body, 'analytics-payment-donut-data');

        $byName = [];
        foreach ($donut['slices'] as $slice) {
            $byName[$slice['is_unassigned'] ? '(none)' : (string) $slice['name']] = $slice;
        }

        self::assertSame(999 + 1000, $byName['Joint card']['minor']);
        self::assertSame('#123456', $byName['Joint card']['colour']);
        // The European row, at the fixture's rate, and the free trial.
        self::assertSame(2000, $byName['(none)']['minor']);
        self::assertArrayNotHasKey('Cash', $byName, 'a lifetime purchase has no monthly cost to break down');

        self::assertSame($total, $donut['total_minor']);
        self::assertSame($total, (int) array_sum(array_column($donut['slices'], 'minor')));

        $card = $this->section($body, 'analytics-payment-methods');
        self::assertStringContainsString('No payment method', $card);
        self::assertStringContainsString('data-unassigned-label="No payment method"', $card);

        // And it is a second chart, not the category one drawn twice.
        self::assertNotSame($this->payload($body, 'analytics-donut-data'), $donut);
    }

    public function testThePaymentMethodDonutDegradesToPerCurrencyFigures(): void
    {
        $owner = $this->scopeFor($this->ownerId);
        $card = (new PaymentMethodRepository($this->db))->create($owner, 'Joint card', null, null, null);
        $this->assignByName('Streaming', $card);

        (new SubscriptionRepository($this->db))->create($owner, [
            'name' => 'Unconvertible',
            'price_minor' => 5000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'payment_method_id' => $card,
            'is_active' => true,
        ], []);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $section = $this->section($body, 'analytics-payment-methods');

        self::assertStringNotContainsString('data-chart="donut"', $section);
        self::assertStringNotContainsString('analytics-payment-donut-data', $body);
        self::assertStringContainsString('XOF', $section);
        self::assertStringContainsString('>GBP<', $section);
        self::assertStringContainsString('Joint card', $section);
        self::assertStringContainsString('meter-fill', $section);
    }

    private function assignByName(string $name, int $methodId): void
    {
        $platform = $this->db->platform();
        $this->db->execute(
            'UPDATE ' . $platform->quoteIdentifier('subscriptions')
            . ' SET ' . $platform->quoteIdentifier('payment_method_id') . ' = :method'
            . ' WHERE ' . $platform->quoteIdentifier('name') . ' = :name',
            ['method' => $methodId, 'name' => $name],
        );
    }

    public function testNotableRanksOnCostPerMonthRatherThanOnTheFacePrice(): void
    {
        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-notable');

        // Hosting is £120 and the largest number in the fixture, but it is
        // £10 a month; the European thing is €40 a month, which is £20. Ranking
        // on the face price would pick Hosting, and ranking on the digits would
        // pick it too.
        self::assertStringContainsString('European thing', $card);
        self::assertStringContainsString('Streaming', $card);
        self::assertStringNotContainsString('Hosting', $card);

        // Shown in the currency it is charged in — the conversion decided the
        // order and nothing else.
        self::assertStringContainsString('€40.00', $card);
        self::assertStringContainsString('£9.99', $card);

        // A lifetime purchase has no monthly cost to be the highest or lowest
        // of, so it is not ranked despite being the largest amount on the page.
        self::assertStringNotContainsString('Lifetime licence', $card);
    }

    /**
     * A trial costs nothing today, and nothing is not a price.
     *
     * Left in, it would take "least expensive" every time and report £0.00 —
     * the figure the trials section on the subscriptions screen refuses to
     * print for exactly this reason. Pricing it at what it converts to was the
     * alternative and is worse: this card and the KPI row would then disagree
     * about one subscription on one page.
     */
    public function testARunningTrialIsNotTheCheapestThingInTheHousehold(): void
    {
        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-notable');

        self::assertStringNotContainsString('Trial plan', $card);
        self::assertStringNotContainsString('£0.00', $card);

        // The real cheapest is still named, so this is an exclusion rather than
        // an empty card.
        self::assertStringContainsString('Streaming', $card);
    }

    public function testASubscriptionThatCannotBeComparedIsExcludedAndCounted(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Unrankable',
            'price_minor' => 900000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'is_active' => true,
        ], []);

        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-notable');

        // 900,000 XOF is the largest figure in the household by a distance, and
        // there is no rate for it, so it has no order against the rest. Left
        // out rather than ranked on its digits — and said so.
        self::assertStringNotContainsString('Unrankable', $card);
        self::assertStringContainsString('1 subscription is not ranked', $card);
    }

    public function testYearOverYearSaysHowManySubscriptionsItCouldNotAccountFor(): void
    {
        // No start date, so there is no evidence of when it began and no
        // charges can be reconstructed for it.
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'No start date',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'is_active' => true,
        ], []);

        // The count the service arrived at, not one this test worked out for
        // itself: the point of the note is that the page reports what was
        // actually left out.
        $excluded = $this->container()
            ->get(SpendHistoryService::class)
            ->yearOverYear($this->scopeFor($this->ownerId))['excluded_count'];

        self::assertGreaterThan(0, $excluded, 'the fixture should leave something out of the comparison');

        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-year-over-year');

        self::assertStringContainsString($excluded . ' subscription', $card);
        self::assertStringContainsString('no start date', $card);
    }

    /**
     * The chart is a picture of a table that is always there.
     *
     * Both sections ship the figures they draw, so a browser that never runs
     * the bundle — and a screen reader, which reads the table rather than the
     * canvas — sees the numbers rather than an empty box.
     */
    public function testEveryChartShipsTheFiguresItDraws(): void
    {
        $body = $this->body($this->get('/stats', $this->ownerId));

        foreach (['analytics-history', 'analytics-trajectory', 'analytics-categories'] as $id) {
            $section = $this->section($body, $id);

            self::assertStringContainsString('chart-figures', $section, $id . ' draws without its figures');
            self::assertStringContainsString('aria-label', $section, $id . ' has an undescribed canvas');
        }
    }

    public function testTheRankingAndItsActionsStillRespectPermissions(): void
    {
        $body = $this->body($this->get('/stats', $this->viewerId));

        // A Viewer sees the page and the figures on it.
        self::assertStringContainsString('Streaming', $body);
        self::assertStringContainsString('analytics-kpis', $body);

        // But is offered no way to record a use, and is refused the endpoint
        // if they forge the request anyway — hiding the control is not the
        // enforcement.
        self::assertStringNotContainsString('/usage', $body, 'a Viewer was shown a mutating control');

        $refused = $this->post('/subscriptions/' . $this->hostingId . '/usage', $this->viewerId);
        self::assertSame(403, $refused->getStatusCode());
    }

    /**
     * The card states a figure and the rows it came from, in one sentence.
     *
     * This is the claim Phase 13 makes and the reason the card is rule-based
     * rather than model-backed: "save £540 a year" is only worth printing if
     * the member can see which subscriptions it refers to and check the
     * arithmetic against their own bill.
     */
    public function testTheInsightCardNamesTheSubscriptionsBehindItsFigure(): void
    {
        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);
        $design = (new \App\Repository\CategoryRepository($this->db))->create($owner, 'Design', null);

        foreach ([['Figma', 3000], ['Canva', 1200]] as [$name, $price]) {
            $subscriptions->create($owner, [
                'name' => $name,
                'price_minor' => $price,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => (new DateTimeImmutable('+15 days'))->format('Y-m-d'),
                'start_date' => '2025-01-01',
                'category_id' => $design,
                'is_active' => true,
            ], []);
        }

        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-insight');

        // Both are named, the cheaper is the one the figure is about, and
        // £12 a month is stated as the £144 a year dropping it removes.
        self::assertStringContainsString('Canva', $card);
        self::assertStringContainsString('Figma', $card);
        self::assertStringContainsString('Design', $card);
        self::assertStringContainsString('£144.00', $card);

        // And the action goes to that subscription rather than to a filter or
        // a report of everything the rule looked at.
        self::assertMatchesRegularExpression('#/subscriptions/\d+/money#', $card);
    }

    /**
     * No rule fired, so there is no card.
     *
     * A featured card reading "nothing to report" would take the most
     * prominent place on the screen to say nothing. The fixture household has
     * no categories, no recorded usage and a trial three weeks off, which is
     * exactly the household the rules have nothing to say about.
     */
    public function testTheCardIsAbsentRatherThanEmptyWhenNothingFired(): void
    {
        $body = $this->body($this->get('/stats', $this->ownerId));

        self::assertStringNotContainsString('analytics-insight', $body);
        // The page still starts where it always did.
        self::assertStringContainsString('analytics-kpis', $body);
    }

    /**
     * Insights are scoped like everything else, because they are made of rows
     * that were.
     *
     * In ISOLATED mode a member sees their own subscriptions, so the rules see
     * their own subscriptions, so the card cannot name somebody else's — there
     * is no path in the insight service that loads a row by id.
     */
    public function testAMemberIsShownNoInsightAboutSomebodyElsesSubscriptions(): void
    {
        $container = $this->container();
        $container->get(\App\Service\InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);
        $design = (new \App\Repository\CategoryRepository($this->db))->create($owner, 'Design', null);

        foreach ([['Figma', 3000], ['Canva', 1200]] as [$name, $price]) {
            $subscriptions->create($owner, [
                'name' => $name,
                'price_minor' => $price,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => (new DateTimeImmutable('+15 days'))->format('Y-m-d'),
                'start_date' => '2025-01-01',
                'category_id' => $design,
                'is_active' => true,
            ], []);
        }

        // The owner's own overlap, which is what the other member must not be
        // shown any part of.
        $owned = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-insight');
        self::assertStringContainsString('Canva', $owned);

        $body = $this->body($this->get('/stats', $this->viewerId));

        self::assertStringNotContainsString('analytics-insight', $body);
        self::assertStringNotContainsString('Canva', $body);
        self::assertStringNotContainsString('Figma', $body);
    }

    /**
     * One section of the page, by id, so an assertion about the donut cannot be
     * satisfied by the KPI row above it.
     */
    private function section(string $html, string $id): string
    {
        $node = (new DOMXPath($this->document($html)))
            ->query(sprintf("//*[@id='%s']", $id))?->item(0);

        if (!$node instanceof DOMElement) {
            self::fail(sprintf('The page has no section with id "%s".', $id));
        }

        return (string) $node->ownerDocument?->saveHTML($node);
    }

    /**
     * The trajectory's payload, fetched as the page is rendered right now.
     *
     * @return array<string, mixed>
     */
    private function trajectory(): array
    {
        return $this->payload(
            $this->body($this->get('/stats', $this->ownerId)),
            'analytics-trajectory-data',
        );
    }

    /**
     * The trajectory's months, keyed by the month they are for, so a test can
     * name the month it is reading rather than counting from an end.
     *
     * @return array<string, array<string, mixed>>
     */
    private function trajectoryMonthsByKey(): array
    {
        /** @var array<string, array<string, mixed>> $keyed */
        $keyed = array_column($this->trajectory()['months'], null, 'key');

        return $keyed;
    }

    /**
     * The JSON a canvas is drawn from, by the id of the script element holding
     * it — the same route the browser takes to it.
     *
     * @return array<string, mixed>
     */
    private function payload(string $html, string $id): array
    {
        $node = (new DOMXPath($this->document($html)))
            ->query(sprintf("//script[@id='%s']", $id))?->item(0);

        if (!$node instanceof DOMElement) {
            self::fail(sprintf('The page has no chart payload with id "%s".', $id));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($node->textContent, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        return $document;
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function scopeFor(int $userId): Scope
    {
        return Scope::forMember(
            $userId,
            false,
            $this->householdId,
            $userId === $this->ownerId ? Role::OwnerAdmin : Role::Viewer,
            IsolationMode::Shared,
        );
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private function get(string $path, int $userId): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, int $userId, array $body = []): ResponseInterface
    {
        $token = $this->container()->get(\App\Security\CsrfTokenManager::class)->token();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withParsedBody($body + [\App\Security\CsrfTokenManager::FIELD_NAME => $token]);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }
}
