<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Palette;
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
 * The colour palette: chosen by each member for themselves, rendered by the
 * server into the root element of every page, and refused — not coerced — when
 * the value is not one of the five.
 *
 * Every test that saves a palette then asks for a page, because the failure
 * worth catching is the one where the choice saves and has no effect.
 */
final class PaletteTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private UserRepository $users;

    private int $ownerId;
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
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->setDemoMode(false);

        $this->users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $hash = (new PasswordHasher())->hash('correct horse battery staple');

        $this->ownerId = $this->users->create('owner@example.test', 'Owner', $hash, false, new DateTimeImmutable());
        $this->viewerId = $this->users->create('viewer@example.test', 'Viewer', $hash, false, new DateTimeImmutable());

        $this->householdId = $households->create('Household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);
    }

    public function testAnAccountThatNeverChoseIsNavy(): void
    {
        self::assertNull($this->storedPalette($this->ownerId));
        self::assertSame(Palette::Navy, Palette::fromNullable(null));

        $this->signIn($this->ownerId);

        self::assertSame('navy', $this->rootAttribute($this->page('/'), 'data-palette'));
    }

    public function testASavedPalettePersistsAndIsOnEveryPage(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('POST', '/profile/palette', ['palette' => 'forest']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('forest', $this->storedPalette($this->ownerId));

        foreach (['/', '/subscriptions', '/profile'] as $path) {
            self::assertSame('forest', $this->rootAttribute($this->page($path), 'data-palette'), $path);
        }

        // A new session — another device — reads it from the account.
        $this->signIn($this->ownerId);
        self::assertSame('forest', $this->rootAttribute($this->page('/'), 'data-palette'));
    }

    /**
     * An unknown value is a tampered form. It is refused with a message, the
     * stored choice is left exactly as it was, and the value never reaches
     * the markup.
     */
    public function testAnUnknownPaletteIsRejectedAndNothingIsWritten(): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/profile/palette', ['palette' => 'ocean']);

        $tampered = 'hotpink"><script>alert(1)</script>';
        $response = $this->request('POST', '/profile/palette', ['palette' => $tampered]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('ocean', $this->storedPalette($this->ownerId), 'A refused palette overwrote the choice.');

        $page = $this->page('/profile');
        self::assertSame('ocean', $this->rootAttribute($page, 'data-palette'));
        self::assertStringContainsString('Choose one of the palettes shown.', $page);
        self::assertStringNotContainsString('hotpink', $page);
    }

    public function testAnEmptyPaletteIsRejectedToo(): void
    {
        $this->signIn($this->ownerId);

        $this->request('POST', '/profile/palette', []);

        self::assertNull($this->storedPalette($this->ownerId));
    }

    /**
     * Self-service: a Viewer can change nothing in the household, and can
     * change how their own pages look. Their choice is theirs alone.
     */
    public function testAViewerChoosesTheirOwnPaletteAndNobodyElsesChanges(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/profile/palette', ['palette' => 'midnight']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('midnight', $this->storedPalette($this->viewerId));
        self::assertSame('midnight', $this->rootAttribute($this->page('/'), 'data-palette'));

        self::assertNull($this->storedPalette($this->ownerId), "The viewer's choice reached the owner.");

        $this->signIn($this->ownerId);
        self::assertSame('navy', $this->rootAttribute($this->page('/'), 'data-palette'));
    }

    public function testThePaletteEndpointRequiresACsrfToken(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('POST', '/profile/palette', ['palette' => 'forest'], withCsrf: false);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->storedPalette($this->ownerId));
    }

    public function testThePaletteEndpointRequiresAnAccount(): void
    {
        $response = $this->request('POST', '/profile/palette', ['palette' => 'forest']);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login', $response->getHeaderLine('Location'));
    }

    /** The root carries the account's theme and palette together, from the server. */
    public function testTheRootCarriesTheAccountsThemeAndPalette(): void
    {
        $this->users->updatePreferences($this->ownerId, ['theme' => 'dark', 'palette' => 'ocean']);
        $this->signIn($this->ownerId);

        $page = $this->page('/');

        self::assertSame('dark', $this->rootAttribute($page, 'data-theme'));
        self::assertSame('ocean', $this->rootAttribute($page, 'data-palette'));
        self::assertMatchesRegularExpression(
            '~^/build/sprite-[^"]+\.svg$~',
            (string) $this->rootAttribute($page, 'data-icon-sprite'),
        );
    }

    public function testASignedOutPageIsNavyAndFollowsTheSystem(): void
    {
        $page = $this->page('/login');

        self::assertSame('navy', $this->rootAttribute($page, 'data-palette'));
        self::assertSame('system', $this->rootAttribute($page, 'data-theme'));
    }

    /**
     * The picker offers all five, marks the current one, and posts to its own
     * CSRF-protected form.
     */
    public function testThePickerOffersEveryPaletteAndMarksTheCurrentOne(): void
    {
        $this->users->updatePreferences($this->ownerId, ['palette' => 'paper']);
        $this->signIn($this->ownerId);

        $page = (string) preg_replace('/\s+/', ' ', $this->page('/profile'));

        self::assertMatchesRegularExpression(
            '~<form method="post" action="/profile/palette"[^>]*> <input type="hidden" name="_csrf"~',
            $page,
        );

        foreach (Palette::cases() as $palette) {
            self::assertStringContainsString(sprintf('data-swatch="%s"', $palette->value), $page);
        }

        self::assertMatchesRegularExpression('~name="palette" value="paper" checked~', $page);
        self::assertDoesNotMatchRegularExpression('~name="palette" value="navy" checked~', $page);
        self::assertStringContainsString('Light &amp; emerald', $page);
    }

    // ----------------------------------------------------------------- tools

    private function storedPalette(int $userId): ?string
    {
        return $this->users->findById($userId)?->palette;
    }

    private function rootAttribute(string $html, string $attribute): ?string
    {
        if (preg_match('~<html\b[^>]*\s' . preg_quote($attribute, '~') . '="([^"]*)"~', $html, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function page(string $path): string
    {
        $response = $this->request('GET', $path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
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
    private function request(string $method, string $path, array $body = [], bool $withCsrf = true): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $request = $request->withParsedBody($body);

            if ($withCsrf) {
                $container = $this->app->getContainer();
                self::assertNotNull($container);
                $request = $request->withHeader(
                    CsrfTokenManager::HEADER_NAME,
                    $container->get(CsrfTokenManager::class)->token(),
                );
            }
        }

        return $this->app->handle($request);
    }
}
