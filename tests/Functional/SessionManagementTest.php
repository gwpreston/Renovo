<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Application\Middleware\SessionMiddleware;
use App\Persistence\PdoSessionHandler;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\RequestContextHolder;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Listing and revoking sessions.
 *
 * The test that earns its place is the last one: revoking a session must make
 * that browser anonymous on its very next request. Anything weaker — a flag to
 * be honoured later, a cache that expires eventually — would not be revocation,
 * and the way to prove it is to hand the real session handler the revoked id
 * and find nothing there.
 */
final class SessionManagementTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private ContainerInterface $container;
    private SessionRepository $sessions;
    private int $userId;
    private int $otherUserId;

    private const CURRENT_SESSION_ID = 'test-session-id';
    private const OTHER_SESSION_ID = 'other-browser-session-id';
    private const THIRD_SESSION_ID = 'third-browser-session-id';

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
        $this->container = $container;

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $this->sessions = new SessionRepository($this->db);

        $this->userId = $users->create('sessions@example.test', 'Session User', 'hash', false, new DateTimeImmutable());
        $this->otherUserId = $users->create('other@example.test', 'Other', 'hash', false, new DateTimeImmutable());

        $householdId = $households->create('Test household', $this->userId);
        $memberships->create($householdId, $this->userId, Role::OwnerAdmin);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $householdId);

        $this->storeSession(self::CURRENT_SESSION_ID, $this->userId, '192.0.2.10', 'Mozilla/5.0 (Macintosh) Firefox/1');
        $this->storeSession(self::OTHER_SESSION_ID, $this->userId, '192.0.2.20', 'Mozilla/5.0 (iPhone) Safari/1');
        $this->storeSession(self::THIRD_SESSION_ID, $this->userId, '192.0.2.30', 'Mozilla/5.0 (Windows) Chrome/1');
        $this->storeSession('someone-elses-session-id', $this->otherUserId, '192.0.2.99', 'Other browser');
    }

    public function testThePageListsThisUsersSessionsOnly(): void
    {
        $response = $this->request('GET', '/profile');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('192.0.2.10', $body);
        self::assertStringContainsString('192.0.2.20', $body);
        self::assertStringContainsString('This device', $body);

        // Another account's session is not this account's business.
        self::assertStringNotContainsString('192.0.2.99', $body);

        // The session id itself never reaches the page; only a short handle.
        self::assertStringNotContainsString(self::OTHER_SESSION_ID, $body);
    }

    /**
     * Device, address and last seen — and nothing that places the address.
     * The line under each device is the address and the time, in that order,
     * with nothing between them where a town would go.
     */
    public function testEachSessionShowsItsAddressAndLastSeenButNoLocation(): void
    {
        $body = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('Safari on iOS', $body);
        self::assertMatchesRegularExpression('~192\.0\.2\.20 · last seen \d~', $body);
        self::assertStringNotContainsString('Location', $body);
    }

    public function testTheOldSecurityAddressLandsOnItsSection(): void
    {
        $response = $this->request('GET', '/settings/security');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile#two-step', $response->getHeaderLine('Location'));
    }

    public function testRevokingOneSessionRemovesItAndLeavesTheRest(): void
    {
        $response = $this->request('POST', '/profile/sessions/revoke', [
            'handle' => substr(self::OTHER_SESSION_ID, 0, 16),
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile#sessions', $response->getHeaderLine('Location'));
        self::assertSame(2, $this->sessions->countActiveForUser($this->userId, new DateTimeImmutable()));
        self::assertNull($this->rowFor(self::OTHER_SESSION_ID));
        self::assertNotNull($this->rowFor(self::THIRD_SESSION_ID));
        self::assertTrue($this->hasAudit(AuditAction::SessionRevoked));
    }

    public function testTheCurrentSessionCannotBeRevokedFromTheList(): void
    {
        $this->request('POST', '/profile/sessions/revoke', [
            'handle' => substr(self::CURRENT_SESSION_ID, 0, 16),
        ]);

        self::assertNotNull($this->rowFor(self::CURRENT_SESSION_ID));
    }

    public function testAHandleFromAnotherAccountRevokesNothing(): void
    {
        $this->request('POST', '/profile/sessions/revoke', [
            'handle' => substr('someone-elses-session-id', 0, 16),
        ]);

        self::assertNotNull($this->rowFor('someone-elses-session-id'));
    }

    public function testRevokingEverythingElseKeepsOnlyTheCurrentSession(): void
    {
        $response = $this->request('POST', '/profile/sessions/revoke-others');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->sessions->countActiveForUser($this->userId, new DateTimeImmutable()));
        self::assertNotNull($this->rowFor(self::CURRENT_SESSION_ID));
        self::assertTrue($this->hasAudit(AuditAction::SessionsRevokedAll));

        // Another account's sessions are untouched.
        self::assertNotNull($this->rowFor('someone-elses-session-id'));
    }

    /**
     * Revocation has to take effect immediately, not on some later sweep.
     */
    public function testARevokedSessionIsUnusableOnTheNextRequest(): void
    {
        $handler = $this->container->get(PdoSessionHandler::class);

        self::assertNotSame('', $handler->read(self::OTHER_SESSION_ID));

        $this->request('POST', '/profile/sessions/revoke', [
            'handle' => substr(self::OTHER_SESSION_ID, 0, 16),
        ]);

        // The handler is what a request actually consults. An empty payload is
        // an anonymous browser: the authentication middleware finds no user id
        // and sends it to the login page.
        self::assertSame('', $handler->read(self::OTHER_SESSION_ID));
    }

    public function testExpiredSessionsAreNotListed(): void
    {
        $this->storeSession(
            'expired-session-id',
            $this->userId,
            '192.0.2.44',
            'Old browser',
            (new DateTimeImmutable())->modify('-1 day'),
        );

        self::assertStringNotContainsString(
            '192.0.2.44',
            (string) $this->request('GET', '/profile')->getBody(),
        );
    }

    /**
     * The session row must keep naming its account when a request ends in an
     * error.
     *
     * The error middleware sits outside the session middleware, so a 403 or 404
     * unwinds straight past it. When the association was written after the
     * handler rather than in a finally, PHP's shutdown handler wrote the row
     * back with no user id — detaching a live session from its account, hiding
     * it from this page and putting it beyond the reach of a password reset's
     * "revoke everything". The session kept working, so nothing complained.
     *
     * This drives the real handler rather than the test session, because the
     * handler is the thing that was getting it wrong.
     */
    public function testAnErrorResponseDoesNotDetachTheSessionFromItsAccount(): void
    {
        $handler = $this->container->get(PdoSessionHandler::class);
        $middleware = new SessionMiddleware(
            $this->session,
            $handler,
            new RequestContextHolder(),
            new CsrfTokenManager($this->session),
        );

        // Cleared first, so the assertion below can only pass if the middleware
        // itself put the association back on the way out.
        $handler->associateUser(null);

        $failing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new HttpNotFoundException($request);
            }
        };

        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/nope',
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        try {
            $middleware->process($request, $failing);
            self::fail('The inner handler was supposed to throw.');
        } catch (HttpNotFoundException) {
            // Expected: this is the path that used to lose the association.
        }

        // The middleware still told the handler who this session belongs to.
        $handler->write(self::CURRENT_SESSION_ID, 'user_id|i:' . $this->userId . ';');

        $row = $this->rowFor(self::CURRENT_SESSION_ID);
        self::assertNotNull($row);
        self::assertSame($this->userId, (int) $row['user_id']);
    }

    private function storeSession(
        string $id,
        int $userId,
        string $ip,
        string $agent,
        ?DateTimeImmutable $expiresAt = null,
    ): void {
        $now = new DateTimeImmutable();
        $expires = $expiresAt ?? $now->modify('+14 days');

        $this->db->insert('sessions', [
            'id' => $id,
            'user_id' => $userId,
            'payload' => 'user_id|i:' . $userId . ';',
            'last_activity' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'ip_address' => $ip,
            'user_agent' => $agent,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ], 'id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowFor(string $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM ' . $this->db->platform()->quoteIdentifier('sessions')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('id') . ' = :id',
            ['id' => $id],
        );
    }

    private function hasAudit(AuditAction $action): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->db->platform()->quoteIdentifier('audit_log')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('action') . ' = :action',
            ['action' => $action->value],
        ) !== null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
