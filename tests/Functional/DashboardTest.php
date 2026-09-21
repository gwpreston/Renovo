<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\DashboardCard;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Repository\DashboardCardRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\BudgetService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\StatsService;
use App\Support\MoneyFormatter;
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
 * The dashboard, rendered through the real application.
 *
 * The claim this phase makes is that every tile binds to a figure a service
 * already produces — so these assert the binding rather than the arithmetic,
 * which the forecast's and the budget's own tests already cover. The chart is
 * checked against the Forecast page's own call for the same data, because "the
 * dashboard and that page cannot disagree" is only true if it is the same call.
 */
final class DashboardTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $viewerId;
    private int $householdId;
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

        $container = $this->app->getContainer();
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

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $subscriptions = new SubscriptionRepository($this->db);

        $subscriptions->create($this->scopeFor($this->ownerId), [
            'name' => 'Streaming',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+3 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // Outside the near window, so the count and the badge have something to
        // exclude rather than only something to include.
        $subscriptions->create($this->scopeFor($this->ownerId), [
            'name' => 'Hosting',
            'price_minor' => 12000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $this->trialId = $subscriptions->create($this->scopeFor($this->ownerId), [
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

        // A second currency that does convert, so the combined total has
        // something to combine.
        $subscriptions->create($this->scopeFor($this->ownerId), [
            'name' => 'European thing',
            'price_minor' => 1000,
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+40 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $subscriptions->create($this->scopeFor($this->editorId), [
            'name' => 'Editors own thing',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+9 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $budgets = $container->get(BudgetService::class);
        $budgets->create($this->scopeFor($this->ownerId), [
            'name' => 'Owners budget',
            'period' => 'monthly',
            'amount' => '200.00',
            'currency' => 'GBP',
        ]);
        $budgets->create($this->scopeFor($this->editorId), [
            'name' => 'Editors budget',
            'period' => 'monthly',
            'amount' => '10.00',
            'currency' => 'GBP',
        ]);
    }

    public function testTheMetricRowShowsTheFourTilesAndACombinedTotal(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('Monthly spend', $body);
        self::assertStringContainsString('Yearly spend', $body);
        self::assertStringContainsString('Renewing soon', $body);
        self::assertStringContainsString('Active subscriptions', $body);

        // Nothing that was dropped in Phase 8 for having no data behind it.
        self::assertStringNotContainsString('VISA', $body);
        self::assertStringNotContainsString('Manage Balance', $body);
        self::assertStringNotContainsString('Upgrade', $body);

        // Both currencies are named, and the combined figure is shown as well
        // because both of them convert. The expected total is asked of the
        // service rather than written down here: the assertion is that the
        // tile shows what the application computed, not that the arithmetic
        // is what this test remembers it being.
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $stats = $container->get(StatsService::class)->dashboard($this->scopeFor($this->ownerId));
        $combined = $stats['combined_monthly']['amount_minor'];
        self::assertNotNull($combined, 'both currencies convert, so there should be a combined total');

        self::assertStringContainsString('€10.00', $body);
        self::assertStringContainsString(
            $container->get(MoneyFormatter::class)->formatMinor($combined, 'GBP'),
            $body,
            'the combined monthly total was missing',
        );
    }

    public function testTheRenewalsTileCountsOnlyTheNearWindow(): void
    {
        // Streaming in three days and the editor's in nine are inside the
        // fourteen-day window; hosting in two hundred days is not, and the
        // trial has no payment date at all until it converts.
        $body = $this->body($this->get('/', $this->ownerId));

        self::assertSame(
            2,
            $this->renewalsCount($body),
            'the renewals tile should count the subscriptions renewing inside the near window',
        );
    }

    public function testTheChartIsTheSameFiguresTheForecastPageShows(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $months = $container->get(ForecastService::class)->monthly(
            $this->scopeFor($this->ownerId),
            ForecastService::DEFAULT_MONTHS,
        );

        $payload = $this->chartPayload($this->body($this->get('/', $this->ownerId)));

        self::assertNotNull($payload, 'the chart payload was not rendered');
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
     * The chart draws spend twice: every charge the horizon holds, and the
     * same months with the trials taken out. The gap between them is what
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
     */
    public function testTheCommittedLineExcludesEveryChargeATrialCausesNotJustItsConversion(): void
    {
        $payload = $this->chartPayload($this->body($this->get('/', $this->ownerId)));

        self::assertNotNull($payload, 'the chart payload was not rendered');
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
        $payload = $this->chartPayload($this->body($this->get('/', $this->ownerId)));

        self::assertNotNull($payload, 'the chart payload was not rendered');

        foreach ($payload['months'] as $month) {
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
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $scope = $this->scopeFor($this->ownerId);
        $effective = (new DateTimeImmutable('+100 days'));
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

        $history = $container->get(PriceHistoryService::class);
        $history->recordInitialPrice(
            $scope,
            $id,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->ownerId,
        );

        $before = $this->monthsByKey($this->body($this->get('/', $this->ownerId)));

        $history->schedule($scope, $id, [
            'price' => '25.00',
            'currency' => 'GBP',
            'effective_from' => $effective->format('Y-m-d'),
        ]);

        $after = $this->monthsByKey($this->body($this->get('/', $this->ownerId)));

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
     */
    public function testBothSeriesAreNamedInTheLegendAndTheTable(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));
        $figures = $this->chartFigures($body);

        self::assertStringContainsString('chart-legend', $body);
        self::assertStringContainsString('Including trial conversions', $figures);
        self::assertStringContainsString('Excluding trial conversions', $figures);
        self::assertSame(2, substr_count($figures, '<th scope="col" class="numeric">'));

        // The description the canvas carries, which is the chart for a reader
        // who is given one sentence rather than a picture.
        self::assertStringContainsString('one line including trial conversions and one excluding them', $body);
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
    public function testWithNoTrialInTheHorizonTheChartDropsToASingleSeries(): void
    {
        (new SubscriptionRepository($this->db))->delete($this->scopeFor($this->ownerId), $this->trialId);

        $body = $this->body($this->get('/', $this->ownerId));
        $payload = $this->chartPayload($body);

        self::assertNotNull($payload, 'the chart payload was not rendered');
        self::assertFalse($payload['has_trials']);

        foreach ($payload['months'] as $month) {
            self::assertSame($month['minor'], $month['committed_minor'], 'month ' . $month['key']);
            self::assertSame(0, $month['trial_minor'], 'month ' . $month['key']);
        }

        self::assertStringNotContainsString('chart-legend', $body);

        // Asserted against the table rather than the page: every page carries
        // the browser's own copy of the catalogue, second series included, so
        // a bare string match would find the label whatever the chart drew.
        $figures = $this->chartFigures($body);
        self::assertStringNotContainsString('Excluding trial conversions', $figures);
        self::assertSame(1, substr_count($figures, '<th scope="col" class="numeric">'));

        self::assertStringNotContainsString(
            'one line including trial conversions and one excluding them',
            $body,
            'the canvas still describes a second line that is not drawn',
        );
    }

    public function testAMissingRateWithholdsBothTheCombinedTotalAndTheChart(): void
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

        $body = $this->body($this->get('/', $this->ownerId));

        self::assertNull(
            $this->chartPayload($body),
            'a month that cannot be combined must not be drawn as a short bar',
        );
        self::assertStringContainsString('XOF', $body);
        self::assertStringContainsString('no exchange rate is available', $body);
    }

    public function testTheUpcomingAndTrialCardsCarryALogoOrTheFallbackMark(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        // The sprite the fallback points at, and the fallback itself. These
        // cards had no logo at all before; the assertion that matters is that
        // a subscription with none still gets a mark rather than a ragged row
        // where some names are indented and others are not.
        self::assertStringContainsString('id="renovo-mark"', $body);
        self::assertStringContainsString('logo-fallback', $body);
    }

    public function testComingSoonLooksFortyFiveDaysAheadRatherThanSeven(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));
        $card = $this->comingSoonCard($body);

        self::assertStringContainsString('Next payments in the following 45 days', $card);

        // Forty days away: inside the new window and outside both of the two
        // this card used to have, so it is the row that proves the window
        // actually widened rather than the heading merely changing.
        self::assertStringContainsString('European thing', $card);
        self::assertStringContainsString('Streaming', $card);
        self::assertStringContainsString('Editors own thing', $card);

        // Two hundred days away. A window that let this in would not be a
        // window.
        self::assertStringNotContainsString('Hosting', $card);

        // The card it replaced is gone from the page, not merely retitled.
        self::assertStringNotContainsString('Next 30 days', $body);
    }

    public function testAChargeInsideAWeekIsMarkedAndOneBeyondItIsNot(): void
    {
        $card = $this->comingSoonCard($this->body($this->get('/', $this->ownerId)));

        // Three days away, so the countdown carries the state as well as the
        // number; nine days away, so it carries only the number. The threshold
        // is StatsService::UPCOMING_URGENT_DAYS, and this is the assertion that
        // pins it: moving it breaks one of these two lines.
        self::assertMatchesRegularExpression(
            '/renewal-days is-due-soon"[^>]*>\s*3 days/',
            $card,
            'a charge three days away is not marked as due soon',
        );
        self::assertMatchesRegularExpression(
            '/renewal-days "[^>]*>\s*9 days/',
            $card,
            'a charge nine days away is marked as due soon when it should not be',
        );

        // The date it falls on is no longer what the row says.
        self::assertStringNotContainsString('renewal-date', $card);
    }

    /**
     * The Coming soon card alone.
     *
     * Sliced out rather than asserted against the whole page: every name in it
     * also appears in the subscriptions table below, so a bare
     * assertStringNotContainsString against the body would fail on a row that
     * is correctly absent from the card and correctly present in the table.
     */
    private function comingSoonCard(string $html): string
    {
        $pattern = '/<h2>Coming soon<\/h2>(.*?)<\/section>/s';
        self::assertSame(1, preg_match($pattern, $html, $matches), 'the Coming soon card is not on the page');

        return $matches[1];
    }

    public function testTheStatusBadgesAreComputedFromRealState(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Paused thing',
            'price_minor' => 100,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+2 days'))->format('Y-m-d'),
            'is_active' => false,
        ], []);

        $rows = $this->body($this->get('/?show=all', $this->ownerId));

        self::assertStringContainsString('badge-renewing', $rows);
        self::assertStringContainsString('badge-trial', $rows);
        self::assertStringContainsString('badge-paused', $rows);

        // The paused row is only in the All view; Active does not claim to
        // show it and then show it.
        self::assertStringNotContainsString('Paused thing', $this->body($this->get('/', $this->ownerId)));
    }

    public function testAChipSwapsTheRowsAndNotTheChart(): void
    {
        $response = $this->get('/?show=expiring', $this->ownerId, ['HX-Request' => 'true']);
        $fragment = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="dashboard-recent"', $fragment);
        self::assertStringNotContainsString('<html', $fragment);
        self::assertStringNotContainsString('data-chart="spend"', $fragment);

        // Expiring is the near window, so the two rows renewing inside it are
        // there and the one two hundred days out is not.
        self::assertStringContainsString('Streaming', $fragment);
        self::assertStringNotContainsString('Hosting', $fragment);
    }

    public function testTheUsageWidgetShowsThisMembersOwnBudget(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('Owners budget', $body);
        self::assertStringNotContainsString('Editors budget', $body);
    }

    public function testAMemberWithNoBudgetIsInvitedToSetOneRatherThanShownALimit(): void
    {
        $body = $this->body($this->get('/', $this->viewerId));

        self::assertStringContainsString('No budget set', $body);
        // A Viewer cannot manage budgets, so they are not offered a link that
        // the middleware would refuse.
        self::assertStringNotContainsString('/budgets/new', $body);
    }

    public function testAViewerIsOfferedNoMutatingControl(): void
    {
        $body = $this->body($this->get('/', $this->viewerId));

        self::assertStringNotContainsString('/settings/backup/export', $body);
        self::assertStringNotContainsString('/subscriptions/new', $body);
    }

    public function testIsolatedModeKeepsAnotherMembersRowsOutOfTheTable(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $container->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $body = $this->body($this->get('/?show=all', $this->editorId));

        self::assertStringContainsString('Editors own thing', $body);
        self::assertStringNotContainsString('Streaming', $body);
    }

    public function testAnAccountThatHasAlreadyArrangedItsDashboardStillGetsTheNewTiles(): void
    {
        // The layout as it was saved before this phase existed: five cards,
        // none of which are the three it adds.
        //
        // `per_period` stays in this fixture on purpose even though no enum
        // case answers to it any more. It is what a real saved layout from
        // before the tile was removed still holds, so this is also the test
        // that a key the enum has forgotten is passed over rather than fatal.
        (new DashboardCardRepository($this->db))->replaceFor($this->ownerId, [
            ['card_key' => 'trials', 'position' => 0, 'visible' => true],
            ['card_key' => 'totals', 'position' => 1, 'visible' => true],
            ['card_key' => 'upcoming', 'position' => 2, 'visible' => true],
            ['card_key' => 'per_period', 'position' => 3, 'visible' => true],
            ['card_key' => 'by_category', 'position' => 4, 'visible' => true],
        ]);

        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('data-chart="spend"', $body, 'the chart went missing');
        self::assertStringContainsString('id="dashboard-recent"', $body, 'the table went missing');
        self::assertStringContainsString('Where it goes', $body, 'the usage widget went missing');
    }

    public function testAHiddenCardStaysHidden(): void
    {
        (new DashboardCardRepository($this->db))->replaceFor($this->ownerId, [
            ['card_key' => DashboardCard::SpendChart->value, 'position' => 0, 'visible' => false],
        ]);

        self::assertStringNotContainsString(
            'data-chart="spend"',
            $this->body($this->get('/', $this->ownerId)),
        );
    }

    /**
     * The per-period tile is gone. The same four figures are the Analytics
     * page's own subject, and the dashboard was the second place saying them.
     *
     * Asserted on "Per day" and "Per week" rather than "Per month", which is
     * also the By category table's column heading and would fail here for the
     * wrong reason.
     */
    public function testThePerPeriodFiguresAreLeftToTheAnalyticsPage(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringNotContainsString('Per day', $body);
        self::assertStringNotContainsString('Per week', $body);
    }

    /**
     * Coming soon and By category take half the grid each, next to each other.
     *
     * The spans are `DashboardCard`'s own unit test; what this adds is that
     * the page really emits them, and that nothing sits between the two cards
     * in the default order — a half-width card with a full-width one after it
     * would leave the hole this change exists to close.
     */
    public function testComingSoonAndByCategorySitBesideEachOther(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        $comingSoon = strpos($body, 'bento-span-3');
        self::assertNotFalse($comingSoon, 'no half-width card was rendered');

        $byCategory = strpos($body, 'bento-span-3', $comingSoon + 1);
        self::assertNotFalse($byCategory, 'only one half-width card was rendered');

        self::assertStringNotContainsString(
            'bento-span-6',
            substr($body, $comingSoon, $byCategory - $comingSoon),
            'a full-width card came between the two halves',
        );
    }

    /**
     * The chart's payload, as the browser would read it, or null when the card
     * decided there was nothing honest to draw.
     *
     * @return array<string, mixed>|null
     */
    private function chartPayload(string $html): ?array
    {
        $pattern = '/<script id="dashboard-spend-data" type="application\/json">(.*?)<\/script>/s';
        if (preg_match($pattern, $html, $matches) !== 1) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * The table of figures beside the chart — what a reader without
     * JavaScript, or with a screen reader, is actually given.
     *
     * Read as its own fragment rather than matched against the whole page:
     * the layout writes the browser's translation catalogue into every page,
     * so both series' labels appear in the HTML whether the chart drew them
     * or not.
     */
    private function chartFigures(string $html): string
    {
        if (preg_match('/<div class="chart-figures">(.*?)<\\/div>/s', $html, $matches) !== 1) {
            self::fail('The page has no chart figures table.');
        }

        return $matches[1];
    }

    /**
     * The chart's months, keyed by the month they are for.
     *
     * @return array<string, array<string, mixed>>
     */
    private function monthsByKey(string $html): array
    {
        $payload = $this->chartPayload($html);
        self::assertNotNull($payload, 'the chart payload was not rendered');

        /** @var array<string, array<string, mixed>> $keyed */
        $keyed = array_column($payload['months'], null, 'key');

        return $keyed;
    }

    /**
     * The figure on the renewals tile, read out of the tile itself rather than
     * matched loosely: a bare "2" appears all over a dashboard.
     */
    private function renewalsCount(string $html): ?int
    {
        $pattern = '/Renewing soon<\/h2>\s*<p class="stat-value">(\d+)<\/p>/';

        return preg_match($pattern, $html, $matches) === 1 ? (int) $matches[1] : null;
    }

    private function scopeFor(int $userId): Scope
    {
        return Scope::forMember(
            $userId,
            false,
            $this->householdId,
            $userId === $this->ownerId ? Role::OwnerAdmin : Role::Editor,
            IsolationMode::Shared,
        );
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, int $userId, array $headers = []): ResponseInterface
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }
}
