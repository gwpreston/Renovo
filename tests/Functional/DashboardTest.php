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
use App\Service\ForecastService;
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
