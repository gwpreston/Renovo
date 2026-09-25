<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Controller\SecurityController;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
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
 * Phase 27: every account setting on one page, for every role.
 *
 * What is asserted here is the page as a whole — that a Viewer can reach and
 * change all of it, that none of it reaches past the signed-in account, that
 * the appearance form saves the fields it was sent and no others, and that
 * the screens it replaced land on the right section. The behaviour of each
 * card has its own test: AccountSelfServiceTest, SessionManagementTest,
 * PaletteTest and PersonalisationTest.
 */
final class ProfilePageTest extends DatabaseTestCase
{
    private const PASSWORD = 'the-current-password';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private UserRepository $users;

    private int $viewerId;
    private int $ownerId;
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

        $this->users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $hash = (new PasswordHasher())->hash(self::PASSWORD);
        $verified = new DateTimeImmutable();

        $this->ownerId = $this->users->create('owner@example.test', 'Olive Owner', $hash, false, $verified);
        $this->viewerId = $this->users->create('viewer@example.test', 'Vic Viewer', $hash, false, $verified);

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->users->updatePreferences($this->ownerId, ['theme' => 'light', 'palette' => 'navy']);
    }

    // ------------------------------------------------------------ every role

    /**
     * A Viewer may change nothing in the household, and everything here: it
     * all acts on their own account.
     */
    public function testAViewerCanChangeEverySettingOnThePage(): void
    {
        $this->signIn($this->viewerId);

        self::assertSame(200, $this->request('GET', '/profile')->getStatusCode());

        $this->assertLandsOn('/profile', $this->request('POST', '/profile/details', [
            'display_name' => 'Vic Renamed',
            'email' => 'viewer@example.test',
        ]));
        $this->assertLandsOn('/profile#appearance', $this->request('POST', '/profile/preferences', [
            'theme' => 'dark',
            'palette' => 'forest',
            'density' => 'compact',
            'week_start' => '0',
            'landing_view' => 'calendar',
        ]));
        $this->assertLandsOn('/profile#dashboard-cards', $this->request('POST', '/profile/dashboard-cards', [
            'card_position' => ['overview' => ['totals' => '1']],
            'card_visible' => ['overview' => ['totals' => '1']],
        ]));
        $this->assertLandsOn('/profile', $this->request('POST', '/profile/password', [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirm' => 'a-brand-new-password',
            'sign_out_others' => '1',
        ]));

        $viewer = $this->users->findById($this->viewerId);
        self::assertNotNull($viewer);
        self::assertSame('Vic Renamed', $viewer->displayName);
        self::assertSame('dark', $viewer->theme);
        self::assertSame('forest', $viewer->palette);
        self::assertSame('compact', $viewer->density);
        self::assertSame(0, $viewer->weekStart);
        self::assertSame('calendar', $viewer->landingView);
        self::assertTrue((new PasswordHasher())->verify('a-brand-new-password', $viewer->passwordHash));
    }

    /**
     * No route on the page takes the account it acts on from the request, so
     * naming somebody else's id changes only your own.
     */
    public function testNobodyCanChangeAnotherAccountsSettings(): void
    {
        $this->signIn($this->viewerId);

        $this->request('POST', '/profile/details', [
            'display_name' => 'Hijacked',
            'email' => 'viewer@example.test',
            'user_id' => (string) $this->ownerId,
            'id' => (string) $this->ownerId,
        ]);
        $this->request('POST', '/profile/preferences', [
            'theme' => 'dark',
            'palette' => 'plum',
            'user_id' => (string) $this->ownerId,
        ]);

        $owner = $this->users->findById($this->ownerId);
        self::assertNotNull($owner);
        self::assertSame('Olive Owner', $owner->displayName);
        self::assertSame('light', $owner->theme);
        self::assertSame('navy', $owner->palette);

        self::assertSame('Hijacked', $this->users->findById($this->viewerId)?->displayName);
    }

    // ----------------------------------------------------------- appearance

    /**
     * Saved on change, the form may arrive with any subset of its fields, and
     * the language control is not drawn at all while one catalogue exists. A
     * field that was not sent is a field left alone.
     */
    public function testAPreferenceThatWasNotSentIsLeftAsItWas(): void
    {
        $this->users->updatePreferences($this->viewerId, [
            'theme' => 'dark',
            'palette' => 'ocean',
            'density' => 'compact',
            'landing_view' => 'calendar',
            'locale' => 'en',
        ]);
        $this->signIn($this->viewerId);

        $this->request('POST', '/profile/preferences', ['week_start' => '0']);

        $viewer = $this->users->findById($this->viewerId);
        self::assertNotNull($viewer);
        self::assertSame(0, $viewer->weekStart);
        self::assertSame('dark', $viewer->theme);
        self::assertSame('ocean', $viewer->palette);
        self::assertSame('compact', $viewer->density);
        self::assertSame('calendar', $viewer->landingView);
        self::assertSame('en', $viewer->locale);
    }

    /**
     * With script, a save is a 204 naming what the page should now wear, so
     * app.js can restyle it in place.
     */
    public function testASaveOnChangeAnswersWithWhatThePageShouldWear(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/profile/preferences', [
            'theme' => 'dark',
            'palette' => 'forest',
            'density' => 'compact',
        ], htmx: true);

        self::assertSame(204, $response->getStatusCode());

        $trigger = json_decode($response->getHeaderLine('HX-Trigger'), true);
        self::assertIsArray($trigger);
        self::assertSame('dark', $trigger['renovo:preferences']['theme'] ?? null);
        self::assertSame('forest', $trigger['renovo:preferences']['palette'] ?? null);
        self::assertSame('compact', $trigger['renovo:preferences']['density'] ?? null);
        self::assertSame('Your preferences have been saved.', $trigger['renovo:preferences']['message'] ?? null);
    }

    /**
     * A refusal is never a silent 204: the page reloads onto the section with
     * the error on it.
     */
    public function testARefusedSaveOnChangeReloadsTheSectionWithTheError(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/profile/preferences', ['palette' => 'hotpink'], htmx: true);

        self::assertSame('/profile#appearance', $response->getHeaderLine('HX-Redirect'));
        self::assertStringContainsString(
            'Choose one of the palettes shown.',
            (string) $this->request('GET', '/profile')->getBody(),
        );
    }

    /**
     * Every appearance control, and a Save button for a browser without
     * script — but no language control while only English exists.
     */
    public function testTheAppearanceSectionOffersEveryControl(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        foreach (['theme', 'palette', 'density', 'week_start'] as $name) {
            self::assertStringContainsString('type="radio" name="' . $name . '"', $body, $name);
        }
        self::assertStringContainsString('name="landing_view"', $body);
        self::assertStringContainsString('hx-trigger="change"', $body);
        self::assertMatchesRegularExpression(
            '~<noscript>\s*<div class="form-actions">\s*<button type="submit"~',
            $body,
        );
        self::assertStringNotContainsString('name="locale"', $body);
    }

    // ------------------------------------------------------------ two-step

    public function testTheAuthenticatorSaysSinceWhenAndHowManyCodesAreLeft(): void
    {
        $this->db->insert('user_totp', [
            'user_id' => $this->viewerId,
            'secret' => 'not-read-by-this-page',
            'confirmed_at' => '2026-03-03 09:00:00',
            'last_used_step' => null,
            'created_at' => '2026-03-03 08:59:00',
        ], 'user_id');
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('On since 3 Mar 2026 · 0 of 10 recovery codes left', $body);
        self::assertStringContainsString('action="/profile/two-step/totp/disable"', $body);
        self::assertStringContainsString('action="/profile/two-step/recovery-codes"', $body);
    }

    /**
     * Recovery codes are shown on the page every issuing action lands on, and
     * only on the first render of it.
     */
    public function testFreshRecoveryCodesAreShownOnceOnTheProfile(): void
    {
        $this->signIn($this->viewerId);
        $this->session->set(SecurityController::RECOVERY_CODES_KEY, ['abcd-efgh-ijkl']);

        self::assertStringContainsString('abcd-efgh-ijkl', (string) $this->request('GET', '/profile')->getBody());
        self::assertStringNotContainsString('abcd-efgh-ijkl', (string) $this->request('GET', '/profile')->getBody());
    }

    public function testAddingAPasskeyIsHiddenUntilScriptOffersIt(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('<div class="stack" id="passkey-add" hidden>', $body);
        self::assertStringContainsString("optionsUrl: '/profile/two-step/passkeys/options'", $body);
    }

    /**
     * The security routes moved under /profile, and the middleware that
     * holds a member on a temporary password names its /profile paths one at
     * a time — so enrolling a factor stays shut until they have chosen one.
     */
    public function testAMemberOnATemporaryPasswordCannotEnrolAFactor(): void
    {
        $this->users->setMustChangePassword($this->viewerId, true);
        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/profile/two-step/totp');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile', $response->getHeaderLine('Location'));
    }

    // ---------------------------------------------------------- old routes

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movedScreens(): array
    {
        return [
            'your account' => ['/profile/account', '/profile#details'],
            'account security' => ['/settings/security', '/profile#two-step'],
        ];
    }

    /**
     * @dataProvider movedScreens
     */
    public function testEveryOldRouteRedirectsToItsSection(string $old, string $section): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('GET', $old);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($section, $response->getHeaderLine('Location'));

        // And the fragment names a section that is on the page.
        $id = substr($section, (int) strpos($section, '#') + 1);
        self::assertStringContainsString(
            'id="' . $id . '"',
            (string) $this->request('GET', '/profile')->getBody(),
        );
    }

    public function testThePageEndsWithAWayOut(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('<form method="post" action="/logout" class="profile-signout">', $body);
    }

    // ----------------------------------------------------------------- tools

    private function assertLandsOn(string $location, ResponseInterface $response): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($location, $response->getHeaderLine('Location'));
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
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

        if ($method !== 'GET') {
            $container = $this->app->getContainer();
            self::assertNotNull($container);

            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        if ($htmx) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        return $this->app->handle($request);
    }
}
