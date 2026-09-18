<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\CancellationService;
use App\Service\InstanceSettingsService;
use App\Service\SplitService;
use App\Service\StatsService;
use App\Service\SubscriptionService;
use App\Service\UserPreferencesService;
use App\Support\DateFormatter;
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
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The my-subscriptions screen, rendered through the real application.
 *
 * The claim this phase makes is that the restyle pulled three sections forward
 * without inventing a figure or a second query path, so these assert the
 * binding rather than the arithmetic: the strip against the statistics service's
 * own output, the sections against what the data says, and the list against
 * itself. Each assertion is made inside the section it is about — a date or an
 * amount appears all over this page, and a loose match would pass whatever the
 * sections did.
 */
final class SubscriptionsScreenTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $viewerId;
    private int $householdId;

    private int $streamingId;
    private int $sharedId;
    private int $editorsOwnId;

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

        $settings = $container->get(InstanceSettingsService::class);
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
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);

        // Renewing inside the near window, with no notice period: one card, not
        // two, because its cancel-by date *is* its renewal date.
        $this->streamingId = $subscriptions->create($owner, [
            'name' => 'Streaming',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+3 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // A week's notice on a charge twelve days out, so the deadline falls in
        // five days: a cancel-by card, and no renewal card, because the renewal
        // itself is outside neither window but the deadline is the urgent part.
        $subscriptions->create($owner, [
            'name' => 'Gym membership',
            'price_minor' => 3500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+12 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'notice_period_amount' => 7,
            'notice_period_unit' => 'days',
            'is_active' => true,
        ], []);

        // Well outside every window: something has to be absent from each
        // section or "only the near window" is untested.
        $subscriptions->create($owner, [
            'name' => 'Hosting',
            'price_minor' => 12000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // Converting in twenty days — outside the fourteen-day window, so the
        // trials section proves it is not that window in disguise.
        $subscriptions->create($owner, [
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

        // A second currency that converts, so the strip has something to
        // combine.
        $subscriptions->create($owner, [
            'name' => 'European thing',
            'price_minor' => 1000,
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+40 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $this->editorsOwnId = $subscriptions->create($this->scopeFor($this->editorId), [
            'name' => 'Editors own thing',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+9 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // Owned by the Owner, paid for in part by the Editor: the row an
        // ISOLATED instance shows a participant without letting them write it.
        $this->sharedId = $subscriptions->create($owner, [
            'name' => 'Family plan',
            'price_minor' => 1600,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $container->get(SplitService::class)->update($owner, $this->sharedId, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->ownerId => 1, $this->editorId => 1],
        ]);

        // Switched off, and owned by two different people. One paused row would
        // prove the card counts; two owned separately is what lets ISOLATED be
        // told apart from SHARED.
        $subscriptions->create($owner, [
            'name' => 'Paused thing',
            'price_minor' => 700,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+18 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => false,
        ], []);

        $subscriptions->create($this->scopeFor($this->editorId), [
            'name' => 'Editors paused thing',
            'price_minor' => 300,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+19 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => false,
        ], []);
    }

    public function testTheStripShowsTheFiguresTheStatisticsServiceComputed(): void
    {
        $container = $this->container();
        $stats = $container->get(StatsService::class)->dashboard($this->scopeFor($this->ownerId));
        $money = $container->get(MoneyFormatter::class);

        $strip = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-strip');

        // The count is the application's own, not a number this test remembers.
        self::assertStringContainsString('>' . $stats['active_count'] . '<', $strip);

        // Both currencies convert, so the yearly figure is the combined one —
        // the same rule, and the same partial, as the dashboard's tiles.
        $combined = $stats['combined_yearly']['amount_minor'];
        self::assertNotNull($combined, 'both currencies convert, so there should be a combined total');
        self::assertStringContainsString($money->formatMinor($combined, 'GBP'), $strip);

        $soon = $container->get(SubscriptionService::class)->upcoming($this->scopeFor($this->ownerId), 14);
        self::assertStringContainsString('>' . count($soon) . '<', $strip);
    }

    public function testTheStripCountsThePausedSubscriptions(): void
    {
        $strip = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-strip');

        // Two paused rows exist and the instance is SHARED, so the owner sees
        // both of them counted.
        self::assertStringContainsString('Paused / inactive', $strip);
        self::assertStringContainsString('>2<', $strip);
    }

    public function testThePausedFigureIsWhatThoseSubscriptionsWouldCostInAYear(): void
    {
        $money = $this->container()->get(MoneyFormatter::class);
        $strip = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-strip');

        // £7.00 and £3.00 a month, so £120.00 a year between them. Asserted as
        // the figure rather than the arithmetic, because the point of the line
        // is that it answers "what would resuming these cost".
        self::assertStringContainsString($money->formatMinor(12000, 'GBP'), $strip);
    }

    public function testIsolationHidesAnotherMembersPausedSubscriptionFromTheCount(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $strip = $this->section($this->body($this->get('/subscriptions', $this->editorId)), 'subscription-strip');

        // The Editor owns one paused subscription and may not see the Owner's.
        // A card that counted both would be leaking the existence of a row the
        // list itself refuses to show — which is exactly the failure a count
        // computed outside the scoping layer would produce.
        self::assertStringContainsString('>1<', $strip);
    }

    public function testTheSavedViewQueryFieldSurvivesBesideTheCategoryWidget(): void
    {
        $body = $this->body($this->get('/subscriptions', $this->ownerId));

        // The card moved into the column beside the category widget. What must
        // not have moved with it is the id `_list.twig` aims its out-of-band
        // replacement at — there is exactly one of these on the page, wherever
        // the card is drawn, and a second would be an id collision that makes
        // the swap land on the wrong element.
        self::assertSame(
            1,
            substr_count($body, 'id="saved-view-query"'),
            'the saved-view query field must appear exactly once',
        );

        // And it is genuinely below the category widget rather than merely
        // still on the page: the widget's heading comes first in document
        // order, which is what "below Category spending" means in the one
        // column the two share.
        $widget = strpos($body, 'id="category-spending"');
        $savedViews = strpos($body, 'id="saved-views-heading"');

        self::assertIsInt($widget, 'the category widget must be on the page');
        self::assertIsInt($savedViews, 'the saved-views card must be on the page');
        self::assertGreaterThan(
            $widget,
            $savedViews,
            'saved views must come after the category widget',
        );
    }

    public function testFilteringStillReplacesTheSavedViewQueryAfterTheMove(): void
    {
        $fragment = $this->body($this->get(
            '/subscriptions?q=Streaming',
            $this->ownerId,
            ['HX-Request' => 'true'],
        ));

        // The list fragment carries the out-of-band replacement, so a view
        // saved after filtering stores the filter rather than the query the
        // page was first loaded with. This is the regression commit 3cad406
        // fixed, and moving the card across columns is where it would return.
        self::assertStringContainsString('id="saved-view-query"', $fragment);
        self::assertStringContainsString('q=Streaming', $fragment);
    }

    public function testASubscriptionWithNoLogoFallsBackToTheRenovoMark(): void
    {
        $list = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-list');

        // None of the fixtures uploads a logo, so every row is the fallback.
        self::assertStringContainsString('logo-fallback', $list);
        self::assertStringContainsString('#renovo-mark', $list);
    }

    public function testTheFallbackMarkResolvesAgainstASpriteThePageActuallyEmits(): void
    {
        $body = $this->body($this->get('/subscriptions', $this->ownerId));

        // A `<use href="#renovo-mark">` with no matching symbol renders nothing
        // at all, and renders nothing *silently* — no console error, no broken
        // image. The sprite is emitted by the shell inside `{% if current_user %}`,
        // so this is the assertion that keeps the fallback from being invisible.
        self::assertStringContainsString('id="renovo-mark"', $body);
    }

    public function testAnUploadedLogoIsShownInsteadOfTheFallback(): void
    {
        $this->db->execute(
            'UPDATE subscriptions SET logo_path = :path WHERE id = :id',
            ['path' => 'assets/logos/streaming.png', 'id' => $this->streamingId],
        );

        $list = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-list');

        self::assertStringContainsString('src="/assets/logos/streaming.png"', $list);
    }

    public function testTheCancelByCardIsOnlyForSubscriptionsWithANoticePeriod(): void
    {
        $body = $this->body($this->get('/subscriptions', $this->ownerId));

        $renewing = $this->section($body, 'renewing-soon');
        $cancelBy = $this->section($body, 'cancel-by');

        // Streaming renews in three days and gives no notice, so its only
        // deadline is the renewal — one card, not the same date twice.
        self::assertStringContainsString('Streaming', $renewing);
        self::assertStringNotContainsString('Streaming', $cancelBy);

        // The gym takes a week's notice, so it has a deadline of its own.
        self::assertStringContainsString('Gym membership', $cancelBy);

        // And nothing outside the near window is in either.
        self::assertStringNotContainsString('Hosting', $renewing);
        self::assertStringNotContainsString('Hosting', $cancelBy);
    }

    public function testEachCardCountsTheDaysToTheDateItPrints(): void
    {
        $body = $this->body($this->get('/subscriptions', $this->ownerId));

        // Streaming renews in three days and the gym's deadline is five days
        // out — the one number a deadline screen exists to show, so it is
        // asserted rather than assumed from the date beside it.
        self::assertStringContainsString('3 days left', $this->section($body, 'renewing-soon'));
        self::assertStringContainsString('5 days left', $this->section($body, 'cancel-by'));
        self::assertStringContainsString('20 days left', $this->section($body, 'free-trials'));
    }

    public function testUrgencyIsTheCancelByViewsOwnJudgement(): void
    {
        $container = $this->container();
        $deadlines = $container->get(CancellationService::class)->deadlines(
            $this->scopeFor($this->ownerId),
            CancellationService::URGENT_DAYS,
        );

        self::assertNotSame([], $deadlines);
        foreach ($deadlines as $row) {
            self::assertTrue(
                $row['is_urgent'],
                'the fixture should put every cancel-by row inside the urgent window',
            );
        }

        // Marked urgent here because that view calls it urgent — one row must
        // not be urgent on one screen and ordinary on the other.
        self::assertStringContainsString('deadline is-urgent', $this->section(
            $this->body($this->get('/subscriptions', $this->ownerId)),
            'cancel-by',
        ));
    }

    public function testATrialShowsTheDayItConvertsAndThePriceItConvertsTo(): void
    {
        $trials = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'free-trials');

        self::assertStringContainsString('Trial plan', $trials);

        // The trial's last day is the day of the first charge, so that is the
        // date shown and the countdown is to it.
        $convertsOn = $this->container()
            ->get(DateFormatter::class)
            ->format(new DateTimeImmutable('+20 days'), 'd MMM y');
        self::assertStringContainsString($convertsOn, $trials);
        self::assertStringContainsString('20 days left', $trials);

        // The amount is what it becomes, never the zero it costs today.
        self::assertStringContainsString('£12.99', $trials);
        self::assertStringNotContainsString('£0.00', $trials);
    }

    public function testTheTrialsSectionIsNotTheNearWindowInDisguise(): void
    {
        // The trial converts in twenty days, outside the fourteen-day window
        // the cards above it are drawn from, and still belongs here.
        $body = $this->body($this->get('/subscriptions', $this->ownerId));

        self::assertStringContainsString('Trial plan', $this->section($body, 'free-trials'));
        self::assertStringNotContainsString('Trial plan', $this->section($body, 'renewing-soon'));
    }

    public function testTheCategoryWidgetIsAShareOfAStatedTotal(): void
    {
        $widget = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'category-spending');

        // Every currency converts here, so there is one total and the bars
        // compare categories across it.
        self::assertStringContainsString('of ', $widget);
        self::assertStringContainsString('meter-fill', $widget);
        self::assertStringNotContainsString('No exchange rate', $widget);
    }

    public function testACurrencyWithNoRateGetsItsOwnBarsRatherThanABlendedOne(): void
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

        $widget = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'category-spending');

        self::assertStringContainsString('No exchange rate for XOF', $widget);
        // A group per currency, each against its own total: GBP, EUR and XOF
        // are named rather than one bar standing for all three.
        self::assertStringContainsString('>XOF<', $widget);
        self::assertStringContainsString('>GBP<', $widget);
        self::assertStringContainsString('meter-fill', $widget);
    }

    public function testAnIsolatedParticipantSeesTheRowButIsOfferedNoTrigger(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $body = $this->body($this->get('/subscriptions', $this->editorId));
        $renewing = $this->section($body, 'renewing-soon');

        // The Editor helps pay for the family plan, so they can see it.
        self::assertStringContainsString('Family plan', $renewing);

        // But they do not own it, and under ISOLATED isolation the read is
        // wider than the write — so the button that would be refused is not
        // drawn, while the one on their own subscription is.
        self::assertStringNotContainsString(
            '/subscriptions/' . $this->sharedId . '/toggle',
            $renewing,
            'a participant was offered an action the repository would refuse',
        );
        self::assertStringContainsString('/subscriptions/' . $this->editorsOwnId . '/toggle', $renewing);
    }

    public function testAViewerIsOfferedNoTriggerAndIsRefusedTheEndpoint(): void
    {
        $body = $this->body($this->get('/subscriptions', $this->viewerId));

        self::assertStringContainsString('Streaming', $this->section($body, 'renewing-soon'));
        self::assertStringNotContainsString('/toggle', $body, 'a Viewer was shown a mutating control');

        // Hiding the control is not the enforcement; this is.
        $refused = $this->post('/subscriptions/' . $this->streamingId . '/toggle', $this->viewerId);
        self::assertSame(403, $refused->getStatusCode());
    }

    public function testFilteringStillSwapsTheListAloneAndNothingElse(): void
    {
        $response = $this->get('/subscriptions?q=Streaming', $this->ownerId, ['HX-Request' => 'true']);
        $fragment = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('<section id="subscription-list"', trim($fragment));
        self::assertStringContainsString('Streaming', $fragment);
        self::assertStringNotContainsString('Hosting', $fragment);

        // None of the sections around the list is in the fragment — they are
        // not recomputed to answer a question about the table.
        self::assertStringNotContainsString('id="subscription-strip"', $fragment);
        self::assertStringNotContainsString('id="renewing-soon"', $fragment);
        self::assertStringNotContainsString('id="category-spending"', $fragment);
    }

    public function testSavedViewsSurviveTheRestyle(): void
    {
        $this->post('/saved-views', $this->ownerId, ['name' => 'Just streaming', 'query' => 'q=Streaming']);

        $body = $this->body($this->get('/subscriptions', $this->ownerId));

        self::assertStringContainsString('Just streaming', $body);
        self::assertStringContainsString('/subscriptions?q=Streaming', $body);
    }

    public function testCompactDensityChangesNoMarkupInTheNewSections(): void
    {
        $comfortable = $this->body($this->get('/subscriptions', $this->ownerId));

        $this->container()->get(UserPreferencesService::class)->update($this->ownerId, [
            'theme' => 'system',
            'locale' => '',
            'week_start' => '1',
            'density' => 'compact',
            'landing_view' => 'dashboard',
        ]);

        $compact = $this->body($this->get('/subscriptions', $this->ownerId));

        self::assertStringContainsString('data-density="compact"', $compact);
        // Density is padding, not a different page: a screen reader sees the
        // same sections either way, which is only true if the markup is.
        foreach (['renewing-soon', 'cancel-by', 'free-trials', 'category-spending'] as $id) {
            self::assertSame(
                $this->section($comfortable, $id),
                $this->section($compact, $id),
                $id . ' renders different markup at compact density',
            );
        }
    }

    /**
     * One section of the page, by id, so an assertion about the trials card
     * cannot be satisfied by the list underneath it.
     */
    private function section(string $html, string $id): string
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        $node = (new DOMXPath($document))->query(sprintf('//*[@id=%s]', $this->xpathLiteral($id)))?->item(0);

        if (!$node instanceof DOMElement) {
            self::fail(sprintf('The page has no section with id "%s".', $id));
        }

        return (string) $document->saveHTML($node);
    }

    private function xpathLiteral(string $value): string
    {
        return "'" . $value . "'";
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
            match ($userId) {
                $this->ownerId => Role::OwnerAdmin,
                $this->viewerId => Role::Viewer,
                default => Role::Editor,
            },
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
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->handle($request, $userId);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, int $userId, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withParsedBody($body + [CsrfTokenManager::FIELD_NAME => $this->csrfToken()]);

        return $this->handle($request, $userId);
    }

    private function handle(ServerRequestInterface $request, int $userId): ResponseInterface
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }

    private function csrfToken(): string
    {
        return $this->container()->get(CsrfTokenManager::class)->token();
    }
}
