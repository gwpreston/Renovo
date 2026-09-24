<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Application\Middleware\InstanceContextMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
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
    private const PASSWORD = 'correct horse battery staple';


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

        $this->userId = $users->create(
            'owner@example.test',
            'Owner',
            (new PasswordHasher())->hash(self::PASSWORD),
            false,
            new DateTimeImmutable(),
        );
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

    /**
     * A view saved after filtering keeps the filter.
     *
     * The failure this exists for: the filter form swaps the list and nothing
     * else, so the hidden field the saved-views form posts is not re-rendered
     * when a filter changes. It went on holding the value the page was first
     * loaded with, and every view saved after filtering stored no filter at
     * all. It saved, it appeared in the list, and following it did nothing —
     * which is exactly the "it saves and has no effect" failure this class was
     * written to catch, and which it missed.
     *
     * It missed it because the test above writes the query itself. That proves
     * the server round trip and steps straight over the hop that was broken:
     * how the browser obtains the value it posts. So nothing here is
     * hand-written. The query is read out of the markup the application
     * actually produced for a filtered list, which is the only version of this
     * test that can fail when the field goes stale again.
     */
    public function testAViewSavedAfterFilteringKeepsTheFilter(): void
    {
        // The page as first loaded. Its field carries the sort and nothing to
        // search for, because nothing has been searched for yet.
        $page = (string) $this->request('GET', '/subscriptions')->getBody();

        self::assertStringNotContainsString(
            'q=Streaming',
            $this->savedViewQuery($page),
            'Nothing has been filtered yet.',
        );

        // Filtering is an htmx request, and it returns the list alone. The
        // field belongs to a card that response does not re-render, so it
        // arrives beside the list as an out-of-band swap.
        $fragment = (string) $this->request(
            'GET',
            '/subscriptions?q=Streaming&sort=name&dir=asc',
            [],
            true,
        )->getBody();

        self::assertStringContainsString(
            'hx-swap-oob',
            $fragment,
            'The list fragment sends the saved-views field back with it.',
        );

        $query = $this->savedViewQuery($fragment);
        self::assertStringContainsString('q=Streaming', $query, 'And it carries the filter that was applied.');

        // What the browser would now post, posted.
        $this->request('POST', '/saved-views', ['name' => 'Streaming only', 'query' => $query]);

        $list = (string) $this->request('GET', '/subscriptions')->getBody();
        self::assertStringContainsString('Streaming only', $list);

        // The view points at the filtered list rather than at all of it, and
        // following it filters — the whole point of having saved it.
        $path = $this->savedViewPath($list, 'Streaming only');
        self::assertStringContainsString('q=Streaming', $path, 'The saved view remembers the filter.');

        $filtered = (string) $this->request('GET', $path)->getBody();

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

    /**
     * Density is a coat of paint, and nothing else.
     *
     * Compact and comfortable are the same markup with different padding —
     * `screens.css` changes a few values under `[data-density='compact']` and
     * no template renders a different table. That property is easy to lose the
     * first time somebody "tidies" a compact list by dropping a column from
     * the markup, and losing it means a reader on a screen reader hears a
     * different page depending on a setting that is supposed to be visual.
     *
     * So the two are compared as text: identical apart from the attribute that
     * selects them. The comparison covers every screen the setting touches
     * rather than one, because the failure would arrive on whichever screen was
     * being tidied.
     *
     * @dataProvider densityScreens
     */
    public function testDensityChangesTheStylingAndNotTheMarkup(string $path): void
    {
        $this->savePreferences(['density' => 'comfortable']);
        $comfortable = (string) $this->request('GET', $path)->getBody();

        $this->savePreferences(['density' => 'compact']);
        $compact = (string) $this->request('GET', $path)->getBody();

        self::assertStringContainsString('data-density="comfortable"', $comfortable);
        self::assertStringContainsString('data-density="compact"', $compact);

        self::assertSame(
            str_replace('data-density="comfortable"', 'data-density="compact"', $comfortable),
            $compact,
            $path . ' renders different markup at the two densities; a screen reader would hear the difference.',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function densityScreens(): array
    {
        return [
            'the dashboard' => ['/'],
            'the list' => ['/subscriptions'],
            'the calendar' => ['/calendar'],
            'analytics' => ['/stats'],
            'budgets' => ['/budgets'],
        ];
    }

    public function testAnUnrecognisedPreferenceFallsBackInsteadOfBeingStored(): void
    {
        $this->savePreferences(['density' => 'enormous', 'week_start' => '9', 'landing_view' => 'nowhere']);

        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $this->userId]);

        self::assertSame('comfortable', $row['density'] ?? null);
        self::assertSame(1, (int) ($row['week_start'] ?? 0));
        self::assertSame('dashboard', $row['landing_view'] ?? null);
    }

    /**
     * "Open on" is about where a session begins, not a permanent redirect.
     *
     * It used to be applied on `/` itself, and the navigation's Dashboard item
     * points at `/` — so choosing any other landing page made the dashboard
     * unreachable from the rail, the phone tab bar and the drawer at once. The
     * assertion that `/` redirects has not been dropped; it has moved to the
     * sign-in below, which is where the preference is now applied.
     */
    public function testTheDashboardIsReachableWhateverTheLandingPreference(): void
    {
        $this->savePreferences(['landing_view' => 'calendar']);

        $response = $this->request('GET', '/');

        self::assertSame(200, $response->getStatusCode(), 'The dashboard must still be reachable at /.');
    }

    public function testSigningInLandsOnTheChosenPage(): void
    {
        $this->savePreferences(['landing_view' => 'calendar']);
        $this->session->clear();

        $response = $this->request('POST', '/login', [
            'email' => 'owner@example.test',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/calendar', $response->getHeaderLine('Location'));
    }

    public function testADeepLinkBeatsTheLandingPreference(): void
    {
        $this->savePreferences(['landing_view' => 'calendar']);
        $this->session->clear();

        // What `AuthenticationMiddleware` does to an unauthenticated GET: it
        // remembers where the person was going. Somebody who asked for the
        // budgets screen and was made to sign in on the way wanted the budgets
        // screen, so the preference must not overrule it.
        $response = $this->request('POST', '/login', [
            'email' => 'owner@example.test',
            'password' => self::PASSWORD,
            'next' => '/budgets',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/budgets', $response->getHeaderLine('Location'));
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
            'card_visible' => ['totals' => '1', 'trials' => '1', 'upcoming' => '1'],
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
            ],
            'card_visible' => [
                'by_category' => '1',
                'totals' => '1',
                'trials' => '1',
                'upcoming' => '1',
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
     * The two pages answer different questions, and each holds only its own.
     *
     * Settings is what somebody decides on everybody else's behalf — a
     * household's name, the instance's currency — and every part of it needs a
     * permission. Profile is what one account sets for itself and needs none.
     * A preference drifting back onto Settings is the regression this catches.
     */
    public function testProfileHoldsYourOwnPreferencesAndSettingsDoesNot(): void
    {
        $profile = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('action="/profile/preferences"', $profile);
        self::assertStringContainsString('name="landing_view"', $profile);
        self::assertStringContainsString('name="card_position[totals]"', $profile, 'The card layout moved too.');

        $settings = (string) $this->request('GET', '/settings')->getBody();

        self::assertStringNotContainsString('name="landing_view"', $settings, 'A preference is still on Settings.');
        // The page's own content, not the shell: the top bar's light/dark
        // toggle posts a `theme` on every page, Settings included.
        $start = (int) strpos($settings, '<main');
        $content = substr($settings, $start, (int) strpos($settings, '</main>') - $start);
        self::assertStringNotContainsString('name="theme"', $content);
        self::assertStringContainsString('action="/settings/household"', $settings, 'Settings kept its own.');
    }

    /**
     * The theme a visitor with no account picks, and where it is kept.
     *
     * A cookie rather than local storage because the palette is decided in the
     * opening `<html>` tag: the point of this test is that the *server*
     * rendered `data-theme`, so the sign-in page arrives in the chosen palette
     * rather than being repainted after the parser has run.
     */
    public function testASignedOutVisitorsThemeCookieDecidesThePalette(): void
    {
        $this->session->clear();

        $html = (string) $this->signedOut('/login', 'dark')->getBody();

        self::assertStringContainsString('data-theme="dark"', $html);
        self::assertMatchesRegularExpression(
            '~data-theme-choice="dark"\s+aria-pressed="true"~',
            $html,
            'The switch should show the choice the page was rendered with.',
        );
    }

    /**
     * A cookie is whatever the machine sends, and it lands in an HTML
     * attribute. Anything that is not one of the three themes resolves to the
     * default rather than being written out — see `Theme::fromString()`.
     */
    public function testAnUnrecognisedThemeCookieFallsBackToTheSystemPalette(): void
    {
        $this->session->clear();

        $html = (string) $this->signedOut('/login', 'neon"><script>')->getBody();

        self::assertStringContainsString('data-theme="system"', $html);
        self::assertStringNotContainsString('neon', $html);
    }

    /**
     * An account's own setting beats a cookie left behind on the machine.
     *
     * The two writers exist for different readers — the cookie for somebody
     * with no account, the setting for somebody with one — and a signed-in
     * page must never consult the cookie, or a shared machine would hand one
     * person's choice to the next person who signs in on it.
     */
    public function testAnAccountsThemeWinsOverACookieOnTheMachine(): void
    {
        $this->savePreferences(['theme' => 'light']);

        $html = (string) $this->signedOut('/', 'dark', signOut: false)->getBody();

        self::assertStringContainsString('data-theme="light"', $html);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function savePreferences(array $values): void
    {
        $response = $this->request('POST', '/profile/preferences', $values);

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
     * The value of the saved-views form's hidden query field, as rendered.
     *
     * Asserts there is exactly one of them, which is the other half of the fix:
     * the full page renders the real field and the fragment renders its
     * out-of-band replacement, and a page carrying both would be two elements
     * sharing an id — the state in which the out-of-band swap stops being able
     * to say which one it means.
     */
    private function savedViewQuery(string $html): string
    {
        $count = preg_match_all('/id="saved-view-query"[^>]*value="([^"]*)"/', $html, $matches);

        self::assertSame(1, $count, 'Exactly one saved-view query field is expected.');

        return html_entity_decode($matches[1][0], ENT_QUOTES, 'UTF-8');
    }

    /** Where a saved view of this name points. */
    private function savedViewPath(string $html, string $name): string
    {
        $pattern = '/<a href="([^"]*)">\s*' . preg_quote($name, '/') . '\s*<\/a>/';

        self::assertSame(1, preg_match($pattern, $html, $matches), 'The saved view should be linked once.');

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * A page fetched with a theme cookie on the request, and — unless the test
     * is about a signed-in reader — with nobody signed in.
     */
    private function signedOut(string $path, string $theme, bool $signOut = true): ResponseInterface
    {
        if ($signOut) {
            $this->session->clear();
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([InstanceContextMiddleware::THEME_COOKIE => $theme]);

        return $this->app->handle($request);
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
