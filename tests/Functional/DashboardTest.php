<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\DashboardView;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Repository\DashboardCardRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\BudgetService;
use App\Service\DashboardLayoutService;
use App\Service\DashboardService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SpendHistoryService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The Overview dashboard, rendered through the real application.
 *
 * The claim the dashboard makes is that every card binds to a figure a service
 * already produces, so these assert the binding rather than re-deriving the
 * arithmetic the forecast's, the reconstruction's and the budget's own tests
 * cover. The chart's two halves are compared with the two services they come
 * from, because "the chart and the Forecast page cannot disagree" is only true
 * if it is the same call.
 *
 * The clock is frozen on Monday 15 June 2026, so "before today", "the next
 * seven days" and "this month" are exact.
 */
final class DashboardTest extends DatabaseTestCase
{
    private const TODAY = '2026-06-15 09:00:00';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $contributorId;
    private int $viewerId;
    private int $householdId;
    private int $trialId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->app = $this->boot(self::TODAY);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Olive Owner', 'hash', false, new DateTimeImmutable());
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, new DateTimeImmutable());
        $this->contributorId = $users->create('contrib@example.test', 'Carl', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->contributorId, Role::Contributor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $subscriptions = new SubscriptionRepository($this->db);

        // Due on Thursday: inside the seven-day window.
        $this->monthly('Streaming', 999, 'GBP', '2026-06-18', '2025-01-18');

        // A yearly bill well outside every window on the page.
        $subscriptions->create($this->scopeFor($this->ownerId), [
            'name' => 'Hosting',
            'price_minor' => 12000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => '2027-01-01',
            'start_date' => '2025-01-01',
            'anchor_day' => 1,
            'is_active' => true,
        ], []);

        // No start date, so the reconstruction leaves it out and says so.
        $this->trialId = $subscriptions->create($this->scopeFor($this->ownerId), [
            'name' => 'Trial plan',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-06-25',
            'converts_to_price_minor' => 1299,
            'is_active' => true,
        ], []);

        // €10 is £5 at the cached rate. Charged on the 10th: already this month.
        $this->monthly('European thing', 1000, 'EUR', '2026-07-10', '2025-01-10');

        $subscriptions->create($this->scopeFor($this->editorId), [
            'name' => 'Editors own thing',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-24',
            'start_date' => '2025-01-24',
            'anchor_day' => 24,
            'is_active' => true,
        ], []);
    }

    public function testTheGreetingSaysWhoAndWhenAndNeverTheTimeOfDay(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('Welcome back, Olive', $body);
        self::assertStringContainsString('Test household stands on Monday 15 June', $body);
        self::assertStringNotContainsString('Good afternoon', $body);
        self::assertStringNotContainsString('Good morning', $body);
    }

    public function testTheFourFiguresAreDrawn(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('Monthly spend', $body);
        self::assertStringContainsString('Yearly run-rate', $body);
        self::assertStringContainsString('at today’s prices', $body);
        self::assertStringContainsString('Due next 7 days', $body);
        self::assertStringContainsString('1 trial · 0 paused', $body);

        $kpis = $this->overview($this->ownerId)['kpis'];
        // Only Streaming falls in 15–22 June; the trial converts on the 25th.
        self::assertSame(1, $kpis['due']['count']);
        self::assertSame(999, $kpis['due']['combined']['amount_minor']);
    }

    /**
     * The note is the tile's own figure over the household's limit. A member's
     * personal budget measures their share, so it would be a wrong percentage
     * of the household's total and there is no note for it.
     */
    public function testTheSpendNoteIsAgainstTheHouseholdBudgetAndNoOther(): void
    {
        $this->budget($this->ownerId, 'Mine', '50.00', 'monthly', (string) $this->ownerId);
        self::assertNull($this->overview($this->ownerId)['kpis']['monthly']['budget']);
        self::assertStringNotContainsString('% of £50.00 budget', $this->body($this->get('/', $this->ownerId)));

        $this->budget($this->ownerId, 'House', '200.00', 'monthly', BudgetService::SUBJECT_HOUSEHOLD);
        $note = $this->overview($this->ownerId)['kpis']['monthly']['budget'];

        self::assertNotNull($note);
        // £9.99 + £120/12 + £5 + £5 = £29.99 a month, of £200.
        self::assertSame(15, $note['percent']);
        self::assertStringContainsString('15% of £200.00 budget', $this->body($this->get('/', $this->ownerId)));
    }

    /**
     * The future half is the Forecast page's months and the past half is the
     * reconstruction, bar for bar; this month is the two added together.
     */
    public function testTheChartsFutureIsTheForecastAndItsPastIsTheReconstruction(): void
    {
        $scope = $this->scopeFor($this->ownerId);
        $chart = $this->overview($this->ownerId)['chart'];

        $forecast = $this->container()->get(ForecastService::class)->monthly($scope, 7);
        $history = $this->container()->get(SpendHistoryService::class)
            ->history($scope, 7, new DateTimeImmutable('2026-06-14'));

        self::assertCount(13, $chart['bars']);
        self::assertSame('2026-06', $chart['bars'][6]['key']);
        self::assertSame('current', $chart['bars'][6]['kind']);

        for ($i = 0; $i < 6; $i++) {
            self::assertSame($history['months'][$i]['combined_minor'], $chart['bars'][$i]['charged_minor']);
            self::assertSame(0, $chart['bars'][$i]['due_minor']);
        }

        self::assertSame($history['months'][6]['combined_minor'], $chart['bars'][6]['charged_minor']);
        self::assertSame($forecast[0]['combined_minor'], $chart['bars'][6]['due_minor']);

        for ($i = 1; $i < 7; $i++) {
            self::assertSame($forecast[$i]['month'], $chart['bars'][$i + 6]['key']);
            self::assertSame($forecast[$i]['combined_minor'], $chart['bars'][$i + 6]['due_minor']);
        }

        // The trial has no start date, and the card says what that costs it.
        self::assertSame(1, $chart['excluded_count']);
        $body = $this->body($this->get('/', $this->ownerId));
        self::assertStringContainsString('Reconstructed from start dates and price history.', $body);
        self::assertStringContainsString('1 subscription has no start date', $body);
    }

    /**
     * A charge due today is the forecast's, not the past's, and appears once.
     */
    public function testAChargeDueTodayIsCountedOnce(): void
    {
        $this->monthly('Due today', 700, 'GBP', '2026-06-15', '2026-01-15');

        $current = $this->overview($this->ownerId)['chart']['bars'][6];

        // Before today: only the €10 on the 10th, which is £5.
        self::assertSame(500, $current['charged_minor']);
        // Today onwards: £7 today, £9.99 on the 18th, £5 on the 24th, and the
        // trial's £12.99 on the 25th.
        self::assertSame(700 + 999 + 500 + 1299, $current['due_minor']);
    }

    /**
     * On the first of a month there is nothing before today in it, and the
     * window does not slide back a month to make up for it.
     */
    public function testOnTheFirstOfTheMonthTheWindowHoldsStill(): void
    {
        $this->app = $this->boot('2026-07-01 09:00:00');

        $bars = $this->overview($this->ownerId)['chart']['bars'];

        self::assertCount(13, $bars);
        self::assertSame('2026-01', $bars[0]['key']);
        self::assertSame('2026-07', $bars[6]['key']);
        self::assertSame(0, $bars[6]['charged_minor']);
        self::assertSame('2027-01', $bars[12]['key']);
    }

    /**
     * With a currency that has no rate, the figures are subtotals, the chart
     * is withheld and the donut degrades — each naming the currency.
     */
    public function testAnUnconvertibleCurrencyWithholdsEveryCombinedFigure(): void
    {
        $this->monthly('Exotic', 5000, 'XOF', '2026-06-20', '2025-01-20');

        $overview = $this->overview($this->ownerId);
        self::assertNull($overview['kpis']['monthly']['combined']['amount_minor']);
        self::assertSame(['XOF'], $overview['kpis']['monthly']['combined']['unconvertible']);
        self::assertFalse($overview['chart']['is_drawable']);
        self::assertNull($overview['where_it_goes']['donut']);

        $body = $this->body($this->get('/', $this->ownerId));
        self::assertStringContainsString(
            'The monthly spend chart is not drawn: no exchange rate is available for XOF',
            $body,
        );
        self::assertStringContainsString('Shown per currency rather than as one chart', $body);
        self::assertStringNotContainsString('class="spend-bars"', $body);
    }

    public function testComingUpIsTheNextThirtyDaysOfTheForecast(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));
        $card = $this->section($body, 'coming-up-card');

        self::assertStringContainsString('Streaming', $card);
        self::assertStringContainsString('Editors own thing', $card);
        self::assertStringContainsString('Trial plan', $card);
        self::assertStringContainsString('Trial converts', $card);
        // 10 July is inside thirty days; €10.00 is roughly £5.00.
        self::assertStringContainsString('European thing', $card);
        self::assertStringContainsString('≈ £5.00', $card);
        self::assertStringNotContainsString('Hosting', $card);
    }

    /**
     * The budgets card reads the calendar month: charged before today, and
     * projected to the month's end. Olive's own budget counts her rows: £5
     * already, then £9.99 and the trial's £12.99 still to come.
     */
    public function testTheBudgetsCardReadsTheCalendarMonth(): void
    {
        $this->budget($this->ownerId, 'Mine', '50.00', 'monthly', (string) $this->ownerId);

        $row = $this->overview($this->ownerId)['budgets'][0];

        self::assertSame(500, $row['charged']?->amountMinor);
        self::assertSame(500 + 999 + 1299, $row['projected']?->amountMinor);
        self::assertFalse($row['is_over']);

        $card = $this->section($this->body($this->get('/', $this->ownerId)), 'budgets-card');
        self::assertStringContainsString('Budgets · June', $card);
        self::assertStringContainsString('£5.00 / £50.00', $card);
        self::assertStringContainsString('£22.02 left this month', $card);
    }

    public function testABudgetOverOnlyIfTheTrialsConvertSaysSo(): void
    {
        // £14.99 committed, £27.98 with the trial: under £20 only without it.
        $this->budget($this->ownerId, 'Tight', '20.00', 'monthly', (string) $this->ownerId);

        $row = $this->overview($this->ownerId)['budgets'][0];
        self::assertFalse($row['is_over']);
        self::assertTrue($row['over_if_trials_convert']);

        self::assertStringContainsString(
            'Projected over if the running trials convert',
            $this->body($this->get('/', $this->ownerId)),
        );
    }

    public function testAMemberWithNoBudgetIsInvitedToSetOneRatherThanShownALimit(): void
    {
        $card = $this->section($this->body($this->get('/', $this->ownerId)), 'budgets-card');

        self::assertStringContainsString('/budgets/new', $card);
    }

    /**
     * Cancel trial is drawn only where the viewer may write the row, and the
     * cancel itself refuses anybody who may not, whatever the page drew.
     */
    public function testCancelTrialIsDrawnOnlyWhereTheRowMayBeWritten(): void
    {
        $action = 'action="/subscriptions/' . $this->trialId . '/cancel"';
        $cancel = '/subscriptions/' . $this->trialId . '/cancel';

        self::assertStringContainsString($action, $this->body($this->get('/', $this->ownerId)));
        // A Contributor reads the household but writes only their own rows.
        self::assertStringNotContainsString($action, $this->body($this->get('/', $this->contributorId)));
        self::assertStringNotContainsString($action, $this->body($this->get('/', $this->viewerId)));

        $forged = $this->post($cancel, $this->contributorId, ['return_to' => '/']);
        self::assertContains($forged->getStatusCode(), [403, 404]);
        self::assertSame(403, $this->post($cancel, $this->viewerId)->getStatusCode());

        $trial = (new SubscriptionRepository($this->db))->find($this->scopeFor($this->ownerId), $this->trialId);
        self::assertNotNull($trial);
        self::assertFalse($trial->isCancelled(), 'Neither forged cancel may land.');

        // And the owner's own cancel lands, and brings them back here.
        $response = $this->post($cancel, $this->ownerId, ['return_to' => '/']);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testThereIsNoKeepItButton(): void
    {
        self::assertStringNotContainsString('Keep it', $this->body($this->get('/', $this->ownerId)));
    }

    public function testThePriceBannerIsAbsentWithNoScheduledRise(): void
    {
        self::assertNull($this->overview($this->ownerId)['price_change']);
        self::assertStringNotContainsString('price-banner', $this->body($this->get('/', $this->ownerId)));
    }

    public function testThePriceBannerNamesTheSoonestOfSeveralRises(): void
    {
        $prices = $this->container()->get(PriceHistoryService::class);
        $scope = $this->scopeFor($this->ownerId);
        $streaming = $this->idOf('Streaming');
        $hosting = $this->idOf('Hosting');

        $prices->recordInitialPrice(
            $scope,
            $streaming,
            Money::of(999, 'GBP'),
            new DateTimeImmutable('2025-01-18'),
            $this->ownerId,
        );
        $prices->recordInitialPrice(
            $scope,
            $hosting,
            Money::of(12000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->ownerId,
        );

        // The later one is recorded first, so the banner cannot be right by
        // taking whichever row came back first.
        $prices->recordCurrentPrice(
            $scope,
            $hosting,
            Money::of(15000, 'GBP'),
            PriceChangeSource::Manual,
            $this->ownerId,
            null,
            new DateTimeImmutable('2026-08-01'),
        );
        $prices->recordCurrentPrice(
            $scope,
            $streaming,
            Money::of(1199, 'GBP'),
            PriceChangeSource::Manual,
            $this->ownerId,
            null,
            new DateTimeImmutable('2026-07-01'),
        );

        $rise = $this->overview($this->ownerId)['price_change'];

        self::assertNotNull($rise);
        self::assertSame('Streaming', $rise['subscription']->name);
        self::assertSame(999, $rise['from']->amountMinor);
        self::assertSame(1199, $rise['to']->amountMinor);
        self::assertSame(200, $rise['monthly_minor']);
        self::assertSame(2400, $rise['annual_minor']);

        $banner = $this->section($this->body($this->get('/', $this->ownerId)), 'price-banner');
        self::assertStringContainsString('Streaming rises from £9.99 to £11.99 on 1 July', $banner);
        self::assertStringContainsString('+£2.00 a month, +£24.00 a year', $banner);
    }

    public function testTheViewChoiceIsRememberedOnTheAccount(): void
    {
        self::assertStringContainsString('coming-up-card', $this->body($this->get('/', $this->ownerId)));

        $response = $this->post('/dashboard/view', $this->ownerId, ['view' => 'household']);
        self::assertSame(302, $response->getStatusCode());

        $user = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertSame(DashboardView::Household, $user?->dashboardViewPreference());

        $body = $this->body($this->get('/', $this->ownerId));
        self::assertStringContainsString('June so far', $body);
        self::assertStringNotContainsString('coming-up-card', $body);
        self::assertMatchesRegularExpression('/value="household"[^>]*aria-pressed="true"/', $body);

        // Another member's dashboard is their own.
        self::assertStringContainsString('coming-up-card', $this->body($this->get('/', $this->editorId)));
    }

    public function testHidingACardInOneViewLeavesTheOtherAlone(): void
    {
        $layout = $this->container()->get(DashboardLayoutService::class);
        $layout->update($this->ownerId, DashboardView::Household, ['by_category' => 1], []);

        self::assertStringContainsString('Where it goes', $this->body($this->get('/', $this->ownerId)));
        self::assertSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'overview'));

        self::assertSame(
            [],
            $layout->visibleFor($this->ownerId, DashboardView::Household),
            'Every Household card was left unticked.',
        );
        self::assertNotSame([], $layout->visibleFor($this->ownerId, DashboardView::Overview));
    }

    /**
     * Saving the profile form with only one view's fields leaves the other
     * view's layout exactly as it was — an absent section is not a section
     * with every card unticked.
     */
    public function testSavingOneViewsLayoutDoesNotHideTheOthersCards(): void
    {
        $this->post('/profile/preferences', $this->ownerId, [
            'card_position' => ['overview' => ['totals' => '1', 'coming_up' => '2']],
            'card_visible' => ['overview' => ['totals' => '1']],
        ]);

        self::assertNotSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'overview'));
        self::assertSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'household'));
    }

    public function testTheSubscriptionsTableIsOffUntilTurnedOn(): void
    {
        self::assertStringNotContainsString('id="dashboard-recent"', $this->body($this->get('/', $this->ownerId)));

        $this->container()->get(DashboardLayoutService::class)->update(
            $this->ownerId,
            DashboardView::Overview,
            ['recent' => 1],
            ['recent' => '1', 'totals' => '1'],
        );

        $body = $this->body($this->get('/', $this->ownerId));
        self::assertStringContainsString('id="dashboard-recent"', $body);
        self::assertStringContainsString('Hosting', $this->section($body, 'recent-card'));

        // A chip asks for the rows alone.
        $fragment = $this->body($this->get('/?show=expiring', $this->ownerId, htmx: true));
        self::assertStringContainsString('<div id="dashboard-recent">', $fragment);
        self::assertStringNotContainsString('<nav', $fragment);
        self::assertStringContainsString('Streaming', $fragment);
        self::assertStringNotContainsString('Hosting', $fragment);
    }

    /**
     * An htmx request to the dashboard that is not a table chip still gets the
     * page, so nothing else that asks for `/` can be handed eight rows.
     */
    public function testOnlyAChipGetsTheTableFragment(): void
    {
        self::assertStringContainsString('<nav', $this->body($this->get('/', $this->ownerId, htmx: true)));
    }

    public function testAViewerIsOfferedNoMutatingControl(): void
    {
        $body = $this->body($this->get('/', $this->viewerId));

        self::assertStringNotContainsString('/subscriptions/new', $body);
        self::assertStringContainsString('/subscriptions/new', $this->body($this->get('/', $this->ownerId)));
    }

    public function testIsolatedModeKeepsAnotherMembersRowsOffTheDashboard(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $body = $this->body($this->get('/', $this->editorId));

        self::assertStringContainsString('Editors own thing', $body);
        self::assertStringNotContainsString('Streaming', $body);
    }

    /**
     * @dataProvider views
     */
    public function testAtMostOneActionOnEitherViewWearsTheAccent(string $view): void
    {
        (new UserRepository($this->db))->updatePreferences($this->ownerId, ['dashboard_view' => $view]);

        self::assertLessThanOrEqual(1, substr_count($this->body($this->get('/', $this->ownerId)), 'button-primary'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function views(): array
    {
        return [['overview'], ['household']];
    }

    /**
     * @return App<ContainerInterface>
     */
    private function boot(string $today): App
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at($today),
        ]);

        $container = $app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        // Nothing may reach a rate provider from a test; the cache below is
        // what the combined figures are computed from.
        $settings->markRatesAttempted(new DateTimeImmutable());

        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable());

        return $app;
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function monthly(string $name, int $priceMinor, string $currency, string $next, string $start): int
    {
        return (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'start_date' => $start,
            'anchor_day' => (int) (new DateTimeImmutable($start))->format('j'),
            'is_active' => true,
        ], []);
    }

    private function budget(int $ownerId, string $name, string $amount, string $period, string $subject): void
    {
        $this->container()->get(BudgetService::class)->create($this->scopeFor($ownerId), [
            'name' => $name,
            'period' => $period,
            'amount' => $amount,
            'currency' => 'GBP',
            'subject_user_id' => $subject,
        ]);
    }

    private function idOf(string $name): int
    {
        foreach ((new SubscriptionRepository($this->db))->findAllForStats($this->scopeFor($this->ownerId)) as $row) {
            if ($row->name === $name) {
                return $row->id;
            }
        }

        self::fail('No subscription named ' . $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(int $userId): array
    {
        return $this->container()->get(DashboardService::class)->overview($this->scopeFor($userId));
    }

    /** One card's markup, found by a class on its section. */
    private function section(string $html, string $class): string
    {
        $start = strpos($html, 'class="card ' . $class);
        self::assertNotFalse($start, 'No card with class ' . $class);

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    private function scopeFor(int $userId): Scope
    {
        $role = match ($userId) {
            $this->ownerId => Role::OwnerAdmin,
            $this->contributorId => Role::Contributor,
            $this->viewerId => Role::Viewer,
            default => Role::Editor,
        };

        return Scope::forMember($userId, false, $this->householdId, $role, IsolationMode::Shared);
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private function get(string $path, int $userId, bool $htmx = false): ResponseInterface
    {
        $this->signIn($userId);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($htmx) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, int $userId, array $body = []): ResponseInterface
    {
        $this->signIn($userId);

        return $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token()),
        );
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }
}
