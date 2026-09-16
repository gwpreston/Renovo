<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
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
 * The preferences a user sets and the views they save.
 *
 * Every one of these is a setting that is easy to write and easy to get wrong
 * in the one way nobody notices: it saves, the page says so, and it has no
 * effect on anything afterwards. So each test changes a preference and then
 * asks for a page.
 */
final class PersonalisationTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $userId;
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
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->setDemoMode(false);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->userId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->householdId = $households->create('Household', $this->userId);
        $memberships->create($this->householdId, $this->userId, Role::OwnerAdmin);

        $subscriptions = new SubscriptionRepository($this->db);
        foreach (['Streaming thing', 'Broadband'] as $name) {
            $subscriptions->create($this->scope(), [
                'name' => $name,
                'price_minor' => 999,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-12-01',
                'is_active' => true,
            ], []);
        }

        $this->signIn();
    }

    public function testASavedViewSurvivesAndReappliesItsFilters(): void
    {
        $response = $this->request('POST', '/saved-views', [
            'name' => 'Only the streaming one',
            'query' => 'q=Streaming&sort=name&dir=asc',
        ]);

        self::assertSame(302, $response->getStatusCode());

        $list = (string) $this->request('GET', '/subscriptions')->getBody();
        self::assertStringContainsString('Only the streaming one', $list, 'The view is offered on the list.');

        // Following it filters the list, which is the only thing that makes a
        // saved view worth saving.
        $filtered = (string) $this->request('GET', '/subscriptions?q=Streaming&sort=name&dir=asc')->getBody();

        self::assertStringContainsString('Streaming thing', $filtered);
        self::assertStringNotContainsString('Broadband', $filtered);
    }

    public function testAViewWithNoNameIsRefused(): void
    {
        $this->request('POST', '/saved-views', ['name' => '  ', 'query' => '']);

        self::assertStringNotContainsString(
            'saved-view-list',
            (string) $this->request('GET', '/subscriptions')->getBody(),
            'Nothing should have been saved.',
        );
    }

    public function testAViewCanBeForgotten(): void
    {
        $this->request('POST', '/saved-views', ['name' => 'Temporary', 'query' => '']);

        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM saved_views');
        self::assertGreaterThan(0, $id);

        $this->request('POST', '/saved-views/' . $id . '/delete');

        self::assertStringNotContainsString('Temporary', (string) $this->request('GET', '/subscriptions')->getBody());
    }

    public function testADensityPreferenceReachesThePage(): void
    {
        $this->savePreferences(['density' => 'compact']);

        self::assertStringContainsString(
            'data-density="compact"',
            (string) $this->request('GET', '/subscriptions')->getBody(),
        );
    }

    public function testAnUnrecognisedPreferenceFallsBackInsteadOfBeingStored(): void
    {
        $this->savePreferences(['density' => 'enormous', 'week_start' => '9', 'landing_view' => 'nowhere']);

        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $this->userId]);

        self::assertSame('comfortable', $row['density'] ?? null);
        self::assertSame(1, (int) ($row['week_start'] ?? 0));
        self::assertSame('dashboard', $row['landing_view'] ?? null);
    }

    public function testTheLandingPreferenceRedirectsTheRoot(): void
    {
        $this->savePreferences(['landing_view' => 'calendar']);

        $response = $this->request('GET', '/');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/calendar', $response->getHeaderLine('Location'));
    }

    public function testTheDashboardStillRendersWhenItIsTheChosenLanding(): void
    {
        $this->savePreferences(['landing_view' => 'dashboard']);

        self::assertSame(200, $this->request('GET', '/')->getStatusCode(), 'And does not redirect to itself.');
    }

    public function testHidingADashboardCardRemovesItFromThePage(): void
    {
        $withCard = (string) $this->request('GET', '/')->getBody();
        self::assertStringContainsString('By category', $withCard, 'The card is there to begin with.');

        $this->savePreferences([
            'card_position' => ['by_category' => '5', 'totals' => '1'],
            // by_category is absent from card_visible, which is how an
            // unticked checkbox arrives.
            'card_visible' => ['totals' => '1', 'trials' => '1', 'upcoming' => '1', 'per_period' => '1'],
        ]);

        self::assertStringNotContainsString('By category', (string) $this->request('GET', '/')->getBody());
    }

    public function testReorderingCardsChangesTheirOrderOnThePage(): void
    {
        $this->savePreferences([
            'card_position' => [
                'by_category' => '1',
                'totals' => '2',
                'trials' => '3',
                'upcoming' => '4',
                'per_period' => '5',
            ],
            'card_visible' => [
                'by_category' => '1',
                'totals' => '1',
                'trials' => '1',
                'upcoming' => '1',
                'per_period' => '1',
            ],
        ]);

        $body = (string) $this->request('GET', '/')->getBody();

        self::assertLessThan(
            strpos($body, 'Next 7 days') ?: PHP_INT_MAX,
            strpos($body, 'By category') ?: PHP_INT_MAX,
            'The card moved to the top should render first.',
        );
    }

    /**
     * The quick-add dialog fetches the real form. If that answer arrived
     * wrapped in a whole HTML document it would put a second <html> inside the
     * page.
     */
    public function testTheQuickAddFormRendersWithoutTheSurroundingPage(): void
    {
        $body = (string) $this->request('GET', '/subscriptions/new', [], htmx: true)->getBody();

        self::assertStringContainsString('name="name"', $body, 'It is still the form.');
        self::assertStringNotContainsString('<!DOCTYPE html>', $body);
        self::assertStringNotContainsString('<nav', $body);
    }

    public function testTheCalendarFollowsTheWeekStartPreference(): void
    {
        $this->savePreferences(['week_start' => '0']);

        $body = (string) $this->request('GET', '/calendar?month=2026-09')->getBody();
        $heading = substr($body, (int) strpos($body, '<thead>'), 400);

        self::assertLessThan(
            strpos($heading, 'Mon') ?: PHP_INT_MAX,
            strpos($heading, 'Sun') ?: PHP_INT_MAX,
            'A Sunday-first reader gets Sunday in the first column.',
        );

        $this->savePreferences(['week_start' => '1']);

        $body = (string) $this->request('GET', '/calendar?month=2026-09')->getBody();
        $heading = substr($body, (int) strpos($body, '<thead>'), 400);

        self::assertLessThan(
            strpos($heading, 'Sun') ?: PHP_INT_MAX,
            strpos($heading, 'Mon') ?: PHP_INT_MAX,
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function savePreferences(array $values): void
    {
        $response = $this->request('POST', '/settings/preferences', $values);

        self::assertSame(302, $response->getStatusCode(), 'The preferences form must have accepted this.');
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->userId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function signIn(): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = [], bool $htmx = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($htmx) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        if ($method !== 'GET') {
            $container = $this->app->getContainer();
            self::assertNotNull($container);

            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
