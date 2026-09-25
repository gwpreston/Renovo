<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\BudgetService;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The structural half of accessibility, on every page.
 *
 * What a screen reader does with a page is decided mostly by things that can
 * be checked mechanically: whether there is one heading that says what the
 * page is, whether the content is in a landmark, whether every control has a
 * name, whether a table's headers say which way they run. None of that is the
 * whole story — the manual pass recorded in PHASE.md covers reading order and
 * whether the wording makes sense out of context — but it is the part that
 * regresses silently when somebody adds a field in a hurry.
 */
final class AccessibilityTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $userId;
    private int $householdId;
    private int $subscriptionId;
    private int $budgetId;
    private int $memberId;

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

        $this->userId = $users->create('owner@example.test', 'Owner', 'hash', true, new DateTimeImmutable());
        $this->householdId = $households->create('Household', $this->userId);
        $memberships->create($this->householdId, $this->userId, Role::OwnerAdmin);

        // Somebody else in the household, so the members screen draws its
        // controls and the removal dialog has a member to ask about.
        $this->memberId = $users->create('editor@example.test', 'Editor', 'hash', false, new DateTimeImmutable());
        $memberships->create($this->householdId, $this->memberId, Role::Editor);

        $scope = Scope::forMember($this->userId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);

        // Two payment methods, one with an uploaded logo and one with only an
        // icon, so the management rows, the form's badge and the list's badge
        // are all rendered in their populated states — the empty list checks
        // none of their controls.
        $methods = new PaymentMethodRepository($this->db);
        $logo = 'assets/logos/' . str_repeat('a', 32) . '.png';
        $withLogo = $methods->create($scope, 'Joint card', '#1069bb', null, $logo);
        $methods->create($scope, 'Direct Debit', null, 'payment-bank', null);

        $this->subscriptionId = (new SubscriptionRepository($this->db))->create(
            $scope,
            [
                'payment_method_id' => $withLogo,
                'name' => 'A subscription',
                'price_minor' => 999,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-12-01',
                'is_active' => true,
            ],
            [],
        );

        // A household budget, so the budget screen is checked with its tiles,
        // a card and the six-month history rather than its empty state, and
        // its edit form is one of the pages below.
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $this->budgetId = $container->get(BudgetService::class)->create($scope, [
            'name' => 'Household',
            'period' => 'monthly',
            'amount' => '50.00',
            'warn_threshold_percent' => '85',
            'subject_user_id' => BudgetService::SUBJECT_HOUSEHOLD,
        ]);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function pages(): array
    {
        return [
            ['/'],
            // The Household dashboard: the same URL, chosen on the account.
            ['household:/'],
            ['/subscriptions'],
            ['/subscriptions/new'],
            ['/subscriptions/{id}/edit'],
            ['/subscriptions/{id}/money'],
            ['/calendar'],
            ['/budgets'],
            ['/budgets/new'],
            ['/forecast'],
            ['/cancellations'],
            ['/stats'],
            ['/categories'],
            ['/payment-methods'],
            ['/profile'],
            ['/settings'],
            ['/settings/members'],
            // The invite dialog's form, as the page it is without script.
            ['/settings/members/invite'],
            ['/settings/members/{member}/remove'],
            ['/settings/notifications'],
            ['/settings/security'],
            ['/settings/api-tokens'],
            ['/settings/backup'],
            ['/import'],
            ['/audit'],
        ];
    }

    /**
     * @dataProvider pages
     */
    public function testEveryPageIsStructurallyNavigable(string $path): void
    {
        $html = $this->get($path);

        self::assertSame(
            1,
            preg_match_all('/<h1\b/', $html),
            sprintf('%s should have exactly one <h1> saying what it is.', $path),
        );

        self::assertStringContainsString('<main', $html, $path . ' has no main landmark.');
        self::assertMatchesRegularExpression('/<html[^>]+lang="[a-zA-Z-]+"/', $html, $path . ' declares no language.');
        self::assertStringContainsString('class="skip-link"', $html, $path . ' has no skip link.');
    }

    /**
     * @dataProvider pages
     */
    public function testEveryControlHasAName(string $path): void
    {
        $html = $this->get($path);

        self::assertSame(
            [],
            $this->unnamedControls($html),
            sprintf('%s has controls a screen reader cannot name.', $path),
        );
    }

    /**
     * @dataProvider pages
     */
    public function testEveryTableHeaderSaysWhichWayItRuns(string $path): void
    {
        $html = $this->get($path);

        preg_match_all('/<th\b([^>]*)>/', $html, $matches);

        foreach ($matches[1] as $attributes) {
            self::assertStringContainsString(
                'scope=',
                $attributes,
                sprintf('%s has a <th> with no scope: a reader cannot tell a row header from a column one.', $path),
            );
        }
    }

    /**
     * @dataProvider pages
     */
    public function testEveryImageIsDescribedOrExplicitlyDecorative(string $path): void
    {
        $html = $this->get($path);

        preg_match_all('/<img\b([^>]*)>/', $html, $matches);

        foreach ($matches[1] as $attributes) {
            self::assertStringContainsString('alt=', $attributes, $path . ' has an image with no alt attribute.');
        }
    }

    /**
     * The quick-add dialog's content: the form fragment htmx loads into it.
     *
     * A fragment has no landmarks, heading or skip link of its own — the page
     * it is loaded into carries those — so only the checks that apply to the
     * markup it brings are made: every control named, every table header
     * scoped, every image described or decorative.
     */
    public function testTheQuickAddDialogsFormIsNamedAndDescribed(): void
    {
        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', 'http://localhost/subscriptions/new', ['REMOTE_ADDR' => '127.0.0.1'])
                ->withHeader('HX-Request', 'true'),
        );
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();

        self::assertStringNotContainsString('<html', $html, 'The dialog was sent a whole document.');
        self::assertSame([], $this->unnamedControls($html), 'The dialog has controls a screen reader cannot name.');

        preg_match_all('/<th\b([^>]*)>/', $html, $headers);
        foreach ($headers[1] as $attributes) {
            self::assertStringContainsString('scope=', $attributes);
        }

        preg_match_all('/<img\b([^>]*)>/', $html, $images);
        foreach ($images[1] as $attributes) {
            self::assertStringContainsString('alt=', $attributes);
        }
    }

    /**
     * The budget dialog's two forms, New and Edit, as the dialog loads them,
     * and the edit form's full page — a URL the static list cannot name.
     */
    public function testTheBudgetFormsAreNamedAndDescribed(): void
    {
        foreach (['/budgets/new', '/budgets/' . $this->budgetId . '/edit'] as $path) {
            $response = $this->app->handle(
                (new ServerRequestFactory())
                    ->createServerRequest('GET', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
                    ->withHeader('HX-Request', 'true'),
            );
            self::assertSame(200, $response->getStatusCode(), $path);
            $html = (string) $response->getBody();

            self::assertStringNotContainsString('<html', $html, $path);
            self::assertSame([], $this->unnamedControls($html), $path . ' has controls a screen reader cannot name.');
        }

        $page = $this->get('/budgets/' . $this->budgetId . '/edit');
        self::assertSame(1, preg_match_all('/<h1\b/', $page));
        self::assertSame([], $this->unnamedControls($page));
    }

    /**
     * The calendar with all three kinds of chip on it, the open day, and the
     * feed card showing its one-time address — the states `/calendar` on an
     * empty month does not reach.
     *
     * Dates are next month's, from the real clock this test runs on: a trial
     * ending on the 10th, a charge on the 12th, and thirty days' notice on a
     * charge due the month after, whose last day to cancel falls in this one.
     */
    public function testTheCalendarsChipsPanelAndFeedAreNamed(): void
    {
        $next = new DateTimeImmutable('first day of next month');
        $scope = Scope::forMember($this->userId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        $subscriptions = new SubscriptionRepository($this->db);

        $subscriptions->create($scope, [
            'name' => 'Trial service',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => $next->modify('+9 days')->format('Y-m-d'),
            'converts_to_price_minor' => 1299,
            'is_active' => true,
        ], []);
        $subscriptions->create($scope, [
            'name' => 'Gym',
            'price_minor' => 4000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next->modify('+1 month +19 days')->format('Y-m-d'),
            'anchor_day' => 20,
            'notice_period_amount' => 30,
            'notice_period_unit' => 'days',
            'is_active' => true,
        ], []);
        $subscriptions->create($scope, [
            'name' => 'Streaming',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next->modify('+11 days')->format('Y-m-d'),
            'anchor_day' => 12,
            'is_active' => true,
        ], []);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $created = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost/calendar/feed-link', ['REMOTE_ADDR' => '127.0.0.1'])
                ->withParsedBody([])
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token()),
        );
        self::assertSame(302, $created->getStatusCode());

        $html = $this->get(
            '/calendar?month=' . $next->format('Y-m') . '&day=' . $next->modify('+9 days')->format('Y-m-d'),
        );

        self::assertStringContainsString('id="calendar-feed-url"', $html, 'The new link was not shown.');
        self::assertSame([], $this->unnamedControls($html), 'The calendar has controls a screen reader cannot name.');
        self::assertSame(1, preg_match_all('/<h1\b/', $html));

        // Every chip in the grid says what it is in a word and an icon, not by
        // its colour alone.
        preg_match_all(
            '~<span class="calendar-chip is-([a-z-]+)">(.*?)</span>\s*</span>~s',
            $html,
            $chips,
            PREG_SET_ORDER,
        );
        self::assertSame(['cancel-by', 'charge', 'trial'], $this->sorted(array_column($chips, 1)));
        foreach ($chips as [, $kind, $inner]) {
            self::assertStringContainsString('<svg', $inner, 'A ' . $kind . ' chip has no icon.');
            self::assertMatchesRegularExpression(
                '~class="visually-hidden">[^<]+:~',
                $inner,
                'A ' . $kind . ' chip has no word.',
            );
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * Controls with neither a wrapping label, a `for=`, nor an aria-label.
     *
     * @return list<string>
     */
    private function unnamedControls(string $html): array
    {
        preg_match_all('/<label[^>]*\bfor="([^"]+)"/', $html, $labels);
        $labelled = $labels[1];

        $unnamed = [];

        preg_match_all('/<(input|select|textarea)\b([^>]*)>/', $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[2] as $index => [$attributes, $offset]) {
            if (str_contains($attributes, 'type="hidden"') || str_contains($attributes, 'aria-label')) {
                continue;
            }

            if (preg_match('/\bid="([^"]+)"/', $attributes, $id) === 1 && in_array($id[1], $labelled, true)) {
                continue;
            }

            // A control wrapped in its own <label> needs no `for`.
            $before = substr($html, 0, $offset);
            if (strrpos($before, '<label') > strrpos($before, '</label>')) {
                continue;
            }

            $unnamed[] = trim($matches[1][$index][0] . ' ' . $attributes);
        }

        return $unnamed;
    }

    private function get(string $path): string
    {
        $path = str_replace(
            ['{id}', '{member}'],
            [(string) $this->subscriptionId, (string) $this->memberId],
            $path,
        );

        if (str_starts_with($path, 'household:')) {
            (new UserRepository($this->db))->updatePreferences($this->userId, ['dashboard_view' => 'household']);
            $path = substr($path, strlen('household:'));
        }

        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost' . $path,
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );

        self::assertSame(200, $response->getStatusCode(), $path . ' did not render.');

        return (string) $response->getBody();
    }
}
