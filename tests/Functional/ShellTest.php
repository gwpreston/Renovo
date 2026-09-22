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
use App\Security\Scope;
use App\Security\SessionInterface;
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
 * The frame every signed-in page is rendered in.
 *
 * `NavigationTest` checks the rules in isolation; this checks that the pages
 * actually get them — that each one arrives inside the shell, with one item
 * marked as where you are, with a rail a narrow screen can do without, and with
 * a link behind every keyboard shortcut. The active item is asserted in the
 * HTML rather than in the service because "decided on the server" is the claim:
 * a highlight a script has to correct after the page paints is a different
 * thing from a highlight that was right when it arrived.
 */
final class ShellTest extends DatabaseTestCase
{
    /**
     * Where a shortcut goes that the shell itself does not link to.
     *
     * Analytics is one rail item over two screens, so the forecast is linked
     * from the statistics page rather than from the navigation — see the design
     * table in PHASE.md. Anything else a shortcut reaches must be in the shell.
     */
    private const LINKED_FROM = ['/forecast' => '/stats'];

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $viewerId;
    private int $householdId;
    private int $subscriptionId;

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

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', true, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());
        $this->householdId = $households->create('Household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->subscriptionId = (new SubscriptionRepository($this->db))->create(
            Scope::forMember($this->ownerId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared),
            [
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

        $this->signIn($this->ownerId);
    }

    /**
     * Every page, and the destination its navigation should be pointing at.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['/', '/'],
            'subscriptions' => ['/subscriptions', '/subscriptions'],
            'a new subscription' => ['/subscriptions/new', '/subscriptions'],
            'editing one' => ['/subscriptions/{id}/edit', '/subscriptions'],
            'its money' => ['/subscriptions/{id}/money', '/subscriptions'],
            'the calendar' => ['/calendar', '/calendar'],
            'budgets' => ['/budgets', '/budgets'],
            'a new budget' => ['/budgets/new', '/budgets'],
            'the forecast' => ['/forecast', '/stats'],
            'cancel-by' => ['/cancellations', '/cancellations'],
            'statistics' => ['/stats', '/stats'],
            'categories' => ['/categories', '/categories'],
            'settings' => ['/settings', '/settings'],
            'your own page' => ['/profile', '/profile'],
            'alerts' => ['/settings/notifications', '/settings/notifications'],
            'security' => ['/settings/security', '/settings'],
            'api tokens' => ['/settings/api-tokens', '/settings'],
            'backup' => ['/settings/backup', '/settings'],
            'import' => ['/import', '/import'],
            'the audit log' => ['/audit', '/audit'],
        ];
    }

    /**
     * @dataProvider pages
     */
    public function testEveryPageRendersInsideTheShell(string $path, string $expectedActive): void
    {
        $html = $this->get($path);

        self::assertStringContainsString('class="sidebar"', $html, $path . ' has no rail.');
        self::assertStringContainsString('class="topbar"', $html, $path . ' has no top bar.');
        self::assertStringContainsString('class="tabbar"', $html, $path . ' has no narrow-screen navigation.');
        self::assertStringContainsString('id="renovo-mark"', $html, $path . ' has no brand mark.');
    }

    /**
     * @dataProvider pages
     */
    public function testEveryPageMarksExactlyOneNavigationItem(string $path, string $expectedActive): void
    {
        $html = $this->get($path);

        self::assertSame(
            [$expectedActive],
            $this->currentHrefs($html),
            sprintf('%s should mark %s, and nothing else, as where you are.', $path, $expectedActive),
        );

        // Twice, not once: the rail and the narrow layout each draw the item,
        // and only one of them is on screen at a time. A marker that arrived in
        // just one would look right on a desktop and be missing on a phone, and
        // the assertion above — which counts destinations, not markers — would
        // not notice.
        self::assertSame(
            2,
            preg_match_all('~aria-current="page"~', $html),
            sprintf('%s should mark where you are in both the rail and the narrow navigation.', $path),
        );
    }

    /**
     * One action per screen wears the accent, and never two.
     *
     * The filled button is the interface saying "this is the thing to do
     * here". Two of them on one page says it twice, which is the same as not
     * saying it: a reader scanning for the action finds a pair and has to read
     * both to work out which one the screen is about.
     *
     * The failure this catches is not a designer writing two on purpose. It is
     * the arithmetic nobody does — a partial that carries a filled button
     * appearing on a screen that already had one, or a second section growing
     * a Save of its own — which is invisible while each template is read on
     * its own and obvious the moment the page is rendered whole. So it is
     * counted on the rendered page rather than in the templates.
     *
     * `.card-featured` is counted with it for the same reason: it is the other
     * treatment reserved for one thing per screen.
     *
     * @dataProvider pages
     */
    public function testAtMostOneActionOnAScreenWearsTheAccent(string $path, string $expectedActive): void
    {
        $html = $this->get($path);

        self::assertLessThanOrEqual(
            1,
            substr_count($html, 'button-primary'),
            $path . ' has more than one filled button; only the screen\'s own action takes the accent.',
        );

        self::assertLessThanOrEqual(
            1,
            substr_count($html, 'card-featured'),
            $path . ' has more than one featured card; the brand wash marks one card per screen.',
        );
    }

    /**
     * The heading is the shell's, and there is one of it.
     *
     * Moving the <h1> into the top bar is what lets a page say what it is
     * without every template drawing its own header; the failure it invites is
     * a page that kept its old one and now has two.
     */
    public function testThePageNameIsInTheTopBarAndNowhereElse(): void
    {
        $html = $this->get('/calendar');

        self::assertSame(1, preg_match_all('/<h1\b/', $html));
        self::assertMatchesRegularExpression('~<h1 class="topbar-title">\s*Billing Calendar\s*</h1>~', $html);
    }

    /**
     * Every keyboard shortcut lands somewhere that is also a link.
     *
     * `g` then a letter is a convenience; the guarantee is that the same place
     * is reachable by clicking. A destination that only the script knew about
     * would vanish for anybody who does not use a keyboard that way, or cannot
     * — and the destinations are read out of the script itself, so adding one
     * without a link to match fails here.
     */
    public function testEveryKeyboardDestinationIsAlsoALinkOnThePage(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.js');
        preg_match('/var DESTINATIONS = \{(.*?)\};/s', $script, $block);
        self::assertNotEmpty($block, 'The shortcut destinations could not be read.');

        preg_match_all("/'([^']+)'/", $block[1], $destinations);
        self::assertNotEmpty($destinations[1]);

        foreach ($destinations[1] as $destination) {
            $page = self::LINKED_FROM[$destination] ?? '/';

            self::assertStringContainsString(
                sprintf('href="%s"', $destination),
                $this->get($page),
                sprintf('`g` reaches %s but nothing on %s links to it.', $destination, $page),
            );
        }
    }

    /**
     * Quick-add degrades. The top bar's action opens a dialog when there is
     * script to open it with, and is a link to the real form when there is not
     * — so the one definition of what a subscription needs is reached either
     * way.
     *
     * It is deliberately not the filled button it used to be: it appears on
     * every screen, and a filled control on all of them leaves no accent for
     * the action a particular screen is actually about. See
     * `testOnlyOneActionOnAScreenWearsTheAccent` below.
     */
    public function testQuickAddIsALinkToTheRealFormBeforeItIsADialog(): void
    {
        $html = $this->get('/');

        self::assertMatchesRegularExpression(
            '~<a class="button topbar-add" href="/subscriptions/new"\s+data-opens-dialog="quick-add">~',
            $html,
        );
    }

    /**
     * What a Viewer is offered. They may read the household and configure their
     * own reminders; they may not import or read the log, and the navigation
     * says so rather than offering them a 403.
     */
    public function testAViewerIsNotOfferedTheScreensTheyWouldBeRefused(): void
    {
        $this->signIn($this->viewerId);

        $html = $this->get('/');

        self::assertStringContainsString('href="/subscriptions"', $html);
        self::assertStringContainsString('href="/settings/notifications"', $html);
        self::assertStringNotContainsString('href="/import"', $html);
        self::assertStringNotContainsString('href="/audit"', $html);

        // And the action they cannot take is not in the bar either.
        self::assertStringNotContainsString('topbar-add', $html);
    }

    /**
     * The shell belongs to signed-in pages. Sign-in has nowhere to navigate to.
     */
    public function testSigningInHasNoShellAndStillExactlyOneHeading(): void
    {
        $this->session->clear();

        $html = $this->get('/login');

        self::assertStringNotContainsString('class="sidebar"', $html);
        self::assertStringNotContainsString('class="tabbar"', $html);
        self::assertSame(1, preg_match_all('/<h1\b/', $html));
    }

    /**
     * What a page with no shell gets instead of one.
     *
     * The frame is hung on the layout's signed-out branch rather than on the
     * sign-in template, so every page reached from it — the password reset
     * behind "Forgotten your password?", the invitation a new member follows —
     * is the same application rather than a bare card. That is what the second
     * path in the loop is checking; the mark is the specific regression, since
     * the sprite defining `#renovo-mark` used to be included only for
     * signed-in pages and a `<use>` of a symbol that is not there draws
     * nothing at all.
     *
     * @dataProvider signedOutPages
     */
    public function testASignedOutPageCarriesTheMarkAndTheThemeSwitch(string $path): void
    {
        $this->session->clear();

        $html = $this->get($path);

        self::assertStringContainsString('id="renovo-mark"', $html, $path . ' has no brand mark to draw.');
        self::assertStringContainsString('class="auth-header"', $html, $path . ' has no branded header.');
        self::assertStringContainsString('data-theme-switch', $html, $path . ' offers no theme switch.');

        // All three states, and exactly one of them marked as current.
        foreach (['system', 'light', 'dark'] as $choice) {
            self::assertStringContainsString('data-theme-choice="' . $choice . '"', $html);
        }

        self::assertSame(1, preg_match_all('~aria-pressed="true"~', $html), $path);
    }

    /**
     * An error page is not a pre-account page, whatever it looks like.
     *
     * `HttpErrorHandler` renders with no user because it cannot know whether
     * there was one — the failure may have come before the account was
     * loaded. The layout would otherwise read that as "before there is an
     * account" and offer a signed-in reader hitting a 404 the sign-in
     * screen's theme switch, which writes a cookie that none of their own
     * pages read: a control that visibly does nothing.
     */
    public function testAnErrorPageIsNotDressedAsASignInScreen(): void
    {
        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost/no-such-page',
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );

        self::assertSame(404, $response->getStatusCode());

        $html = (string) $response->getBody();

        self::assertStringNotContainsString('data-theme-switch', $html, 'The error page offers a theme switch.');
        self::assertStringNotContainsString('class="auth-header"', $html);
        self::assertSame(1, preg_match_all('/<h1\b/', $html), 'An error page still has exactly one heading.');
    }

    /**
     * The pages rendered before there is an account.
     *
     * @return array<string, array{0: string}>
     */
    public static function signedOutPages(): array
    {
        return [
            'signing in' => ['/login'],
            'asking for a reset' => ['/forgot-password'],
        ];
    }

    /**
     * Distinct destinations marked as current in the rendered page.
     *
     * The rail and the narrow layout both draw the active item, so the same
     * href legitimately carries `aria-current` more than once; what must not
     * happen is two different ones.
     *
     * @return list<string>
     */
    private function currentHrefs(string $html): array
    {
        preg_match_all('~href="([^"]+)"\s+aria-current="page"~', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function get(string $path): string
    {
        $path = str_replace('{id}', (string) $this->subscriptionId, $path);

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
