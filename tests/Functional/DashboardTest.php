<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\DashboardCard;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
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
use App\Service\InstanceSettingsService;
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
 * checked against the reconstruction `StatsService` walks, because "the
 * dashboard and the Analytics page cannot disagree" is only true if it is the
 * same call.
 *
 * The screen draws one chart: the year behind. The year ahead was a card here
 * and is now the Analytics page's trajectory alone — `AnalyticsScreenTest`
 * holds the tests for what that chart's two lines mean, which used to live in
 * this file because this is where the chart was.
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

        $subscriptions->create($this->scopeFor($this->ownerId), [
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
            '/renewal-days num is-due-soon"[^>]*>\s*3 days/',
            $card,
            'a charge three days away is not marked as due soon',
        );
        self::assertMatchesRegularExpression(
            '/renewal-days num "[^>]*>\s*9 days/',
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

    /**
     * Asserted on the topbar's add button rather than on anything in a card:
     * with the subscriptions table gone the dashboard's cards offer a Viewer
     * nothing to be refused, and a test whose subject has left the page passes
     * for the wrong reason.
     */
    public function testAViewerIsOfferedNoMutatingControl(): void
    {
        $body = $this->body($this->get('/', $this->viewerId));

        self::assertStringNotContainsString('/subscriptions/new', $body);
        // The same page, for somebody who may add one, really does offer it —
        // so the assertion above is about the role and not about the markup.
        self::assertStringContainsString('/subscriptions/new', $this->body($this->get('/', $this->ownerId)));
    }

    /**
     * Read through the Coming soon card since the subscriptions table left:
     * both fixtures renew inside its window, so an Editor on an ISOLATED
     * instance sees their own charge there and not the Owner's.
     */
    public function testIsolatedModeKeepsAnotherMembersRowsOffTheDashboard(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $container->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $body = $this->body($this->get('/', $this->editorId));

        self::assertStringContainsString('Editors own thing', $body);
        self::assertStringNotContainsString('Streaming', $body);
    }

    public function testAnAccountThatHasAlreadyArrangedItsDashboardStillGetsTheNewTiles(): void
    {
        // The layout as it was saved before this phase existed: five cards,
        // none of which are the three it adds.
        //
        // `per_period` and `recent` stay in this fixture on purpose even
        // though no enum case answers to either any more. They are what a real
        // saved layout from before those tiles were removed still holds, so
        // this is also the test that a key the enum has forgotten is passed
        // over rather than fatal.
        (new DashboardCardRepository($this->db))->replaceFor($this->ownerId, [
            ['card_key' => 'trials', 'position' => 0, 'visible' => true],
            ['card_key' => 'totals', 'position' => 1, 'visible' => true],
            ['card_key' => 'upcoming', 'position' => 2, 'visible' => true],
            ['card_key' => 'per_period', 'position' => 3, 'visible' => true],
            ['card_key' => 'recent', 'position' => 4, 'visible' => true],
            ['card_key' => 'by_category', 'position' => 5, 'visible' => true],
        ]);

        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('data-chart="spend"', $body, 'the chart went missing');
        self::assertStringContainsString('Where it goes', $body, 'the usage widget went missing');
    }

    /**
     * Asserted on the chart card's own payload id rather than on
     * `data-chart="spend"`, which is an attribute every chart in the
     * application carries and so cannot say which card drew one. The layout
     * here names one visible card and one hidden one, so the assertion is
     * that hiding took the chart away and left the rest of the screen alone.
     */
    public function testAHiddenCardStaysHidden(): void
    {
        (new DashboardCardRepository($this->db))->replaceFor($this->ownerId, [
            ['card_key' => DashboardCard::SpendHistory->value, 'position' => 0, 'visible' => false],
            ['card_key' => DashboardCard::BudgetUsage->value, 'position' => 1, 'visible' => true],
        ]);

        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringNotContainsString('dashboard-history-data', $body);
        self::assertStringContainsString(
            'Where it goes',
            $body,
            'hiding one card should not take the rest of the screen with it',
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
     * One chart, and it is the year behind.
     *
     * The dashboard drew the year ahead as well until the two twelve-month
     * charts stacked on one screen became the thing a reader had to tell apart
     * before either had said anything. The forecast is the Analytics page's
     * trajectory and the Forecast page's whole subject, so this asserts both
     * halves of the change: the history is drawn, ending with this month, and
     * the forecast's payload is not on the page at all.
     */
    public function testTheDashboardDrawsTheYearBehindAndLeavesTheYearAheadToAnalytics(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        $history = $this->chartPayload($body);

        self::assertNotNull($history, 'the history payload was not rendered');
        self::assertCount(12, $history['months']);
        self::assertSame(
            (new DateTimeImmutable())->format('Y-m'),
            $history['months'][11]['key'],
            'the window should end with the month it is being read in',
        );

        self::assertNull(
            $this->chartPayload($body, 'dashboard-spend-data'),
            'the forecast chart is the Analytics page\'s; the dashboard should not draw it too',
        );
    }

    /**
     * A currency with no rate withholds the figure rather than understating
     * it, and says which currency did it.
     *
     * The chart half of this claim moved to the Analytics screen with the
     * chart; what is asserted here is the metric row, which is the dashboard's
     * own and where a quietly wrong combined total would do the most damage.
     */
    public function testAMissingRateWithholdsTheCombinedTotal(): void
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

        self::assertStringContainsString('XOF', $body);
        self::assertStringContainsString('no exchange rate is available', $body);
    }

    /**
     * The history's figures agree with the reconstruction the Analytics page's
     * year-over-year card is totalled from, because they are that
     * reconstruction — read through the service rather than recomputed here.
     */
    public function testTheHistoryIsTheReconstructionAndNotASecondOpinion(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $months = $container->get(StatsService::class)->monthlyHistory(
            $this->scopeFor($this->ownerId),
        );

        $payload = $this->chartPayload(
            $this->body($this->get('/', $this->ownerId)),
            'dashboard-history-data',
        );

        self::assertNotNull($payload, 'the history payload was not rendered');
        self::assertCount(count($months), $payload['months']);

        foreach ($months as $index => $month) {
            self::assertSame(
                $month['combined_minor'],
                $payload['months'][$index]['minor'],
                'month ' . $month['month'] . ' disagrees with the reconstruction',
            );
            self::assertIsInt($payload['months'][$index]['minor']);
        }
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
     * A chart's payload, as the browser would read it, or null when the card
     * decided there was nothing honest to draw.
     *
     * Named by id, which is how the canvas finds its own: the dashboard draws
     * the history and the argument for passing a name is that asking for the
     * forecast's — `dashboard-spend-data`, which the Analytics trajectory
     * still carries — should return nothing here.
     *
     * @return array<string, mixed>|null
     */
    private function chartPayload(string $html, string $id = 'dashboard-history-data'): ?array
    {
        $pattern = sprintf(
            '/<script id="%s" type="application\/json">(.*?)<\/script>/s',
            preg_quote($id, '/'),
        );
        if (preg_match($pattern, $html, $matches) !== 1) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * The figure on the renewals tile, read out of the tile itself rather than
     * matched loosely: a bare "2" appears all over a dashboard.
     */
    private function renewalsCount(string $html): ?int
    {
        $pattern = '/Renewing soon<\/h2>\s*<p class="stat-value num">(\d+)<\/p>/';

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

    private function get(string $path, int $userId): ResponseInterface
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path),
        );
    }
}
