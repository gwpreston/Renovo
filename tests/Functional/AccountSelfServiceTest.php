<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
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
use App\Tests\Support\FakeUpload;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * What a member may do to their own account.
 *
 * The three things worth proving are the three that are easy to implement
 * one step too eagerly: that a change of address does not take effect before
 * it has been proved, that a change of password cannot be made by whoever is
 * sitting at an unattended browser, and that an avatar is a picture this
 * application drew rather than bytes somebody uploaded.
 */
final class AccountSelfServiceTest extends DatabaseTestCase
{
    private const PASSWORD = 'the-current-password';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private RecordingMailer $mailer;

    private int $memberId;
    private int $housemateId;
    private int $outsiderId;
    private int $householdId;
    private string $avatarDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->mailer = new RecordingMailer();
        $this->avatarDirectory = sys_get_temp_dir() . '/renovo-avatars-' . bin2hex(random_bytes(6));

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => $this->mailer,
            'settings' => $this->settingsWithAvatarDirectory(),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $verified = new DateTimeImmutable();
        $hash = (new PasswordHasher())->hash(self::PASSWORD);

        $this->memberId = $users->create('member@example.test', 'Mary Member', $hash, false, $verified);
        $this->housemateId = $users->create('house@example.test', 'Hank Housemate', $hash, false, $verified);
        $this->outsiderId = $users->create('out@example.test', 'Otto Outsider', $hash, false, $verified);

        $this->householdId = $households->create('Test household', $this->memberId);
        $memberships->create($this->householdId, $this->memberId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->housemateId, Role::Editor);

        $otherHouseholdId = $households->create('Other household', $this->outsiderId);
        $memberships->create($otherHouseholdId, $this->outsiderId, Role::OwnerAdmin);
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();

        foreach (glob($this->avatarDirectory . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->avatarDirectory . '/*') ?: [] as $folder) {
            @rmdir($folder);
        }
        @rmdir($this->avatarDirectory);

        parent::tearDown();
    }

    // -------------------------------------------------------------- the page

    /**
     * One page, not two.
     *
     * The account cards and the preferences used to be separate screens under
     * the same URL prefix, reached from two corners of the same shell. This is
     * the assertion that they arrive together — by the forms they carry rather
     * than by their headings, because a form's action is the part a reader
     * actually needs to be on the page they were sent to.
     */
    public function testEverythingAboutThisAccountIsOnOnePage(): void
    {
        $this->signIn($this->memberId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        foreach ([
            '/profile/name',
            '/profile/email',
            '/profile/password',
            '/profile/avatar',
            '/profile/preferences',
        ] as $action) {
            self::assertStringContainsString(
                'action="' . $action . '"',
                $body,
                $action . ' is not offered on the profile page',
            );
        }
    }

    /**
     * The old address keeps working.
     *
     * A confirmation email for a change of address links to `/profile/account`
     * and is sent before anybody rearranges a screen, so the path redirects
     * rather than 404s. Asserted because "we will keep the old URL alive" is
     * exactly the kind of promise that is quietly dropped in a later tidy-up.
     */
    public function testTheOldAccountUrlStillLeadsToThePage(): void
    {
        $this->signIn($this->memberId);

        $response = $this->request('GET', '/profile/account');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile', $response->getHeaderLine('Location'));
    }

    // -------------------------------------------------------------- the name

    public function testTheNameChangesAndIsRecorded(): void
    {
        $this->signIn($this->memberId);

        $this->request('POST', '/profile/name', ['display_name' => 'Mary Renamed']);

        self::assertSame('Mary Renamed', $this->reload($this->memberId)->displayName);
        self::assertTrue($this->hasAudit(AuditAction::NameChanged));
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->signIn($this->memberId);

        $this->request('POST', '/profile/name', ['display_name' => '   ']);

        self::assertSame('Mary Member', $this->reload($this->memberId)->displayName);
    }

    // ------------------------------------------------------------- the email

    public function testTheOldAddressStaysTheLoginUntilTheNewOneIsConfirmed(): void
    {
        $this->signIn($this->memberId);

        $this->request('POST', '/profile/email', ['email' => 'new@example.test']);

        $member = $this->reload($this->memberId);
        self::assertSame('member@example.test', $member->email, 'The login must not move yet.');
        self::assertSame('new@example.test', $member->pendingEmail);
        self::assertTrue($this->hasAudit(AuditAction::EmailChangeRequested));

        // Two messages: the link to the new address, and a warning to the old
        // one. The warning is how somebody finds out their account is being
        // taken while they can still stop it.
        self::assertCount(2, $this->mailer->messages);
        self::assertStringContainsString('new@example.test', $this->recipientsOf(0));
        self::assertStringContainsString('member@example.test', $this->recipientsOf(1));

        $token = $this->mailer->lastTokenFromMessage(0);
        self::assertNotNull($token);

        $this->request('GET', '/confirm-email-change?token=' . $token);

        $member = $this->reload($this->memberId);
        self::assertSame('new@example.test', $member->email);
        self::assertNull($member->pendingEmail);
        self::assertTrue($member->isVerified());
        self::assertTrue($this->hasAudit(AuditAction::EmailChanged));
    }

    public function testAnAddressAlreadyInUseIsRefusedWhenItIsAskedFor(): void
    {
        $this->signIn($this->memberId);

        $this->request('POST', '/profile/email', ['email' => 'house@example.test']);

        self::assertNull($this->reload($this->memberId)->pendingEmail);
        self::assertSame([], $this->mailer->messages);
    }

    public function testAnAddressTakenAfterTheRequestIsRefusedAtConfirmationToo(): void
    {
        $this->signIn($this->memberId);
        $this->request('POST', '/profile/email', ['email' => 'later@example.test']);

        $token = $this->mailer->lastTokenFromMessage(0);
        self::assertNotNull($token);

        // Somebody else gets there first, between the request and the click.
        (new UserRepository($this->db))
            ->create('later@example.test', 'First', 'hash', false, new DateTimeImmutable());

        $response = $this->request('GET', '/confirm-email-change?token=' . $token);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('member@example.test', $this->reload($this->memberId)->email);
    }

    public function testASecondRequestReplacesTheFirst(): void
    {
        $this->signIn($this->memberId);

        $this->request('POST', '/profile/email', ['email' => 'first@example.test']);
        $firstToken = $this->mailer->lastTokenFromMessage(0);
        self::assertNotNull($firstToken);

        $this->request('POST', '/profile/email', ['email' => 'second@example.test']);

        self::assertSame('second@example.test', $this->reload($this->memberId)->pendingEmail);
        self::assertSame(410, $this->request('GET', '/confirm-email-change?token=' . $firstToken)->getStatusCode());
        self::assertSame('member@example.test', $this->reload($this->memberId)->email);
    }

    // ---------------------------------------------------------- the password

    public function testAWrongCurrentPasswordChangesNothing(): void
    {
        $this->signIn($this->memberId);
        $before = $this->reload($this->memberId)->passwordHash;

        $this->request('POST', '/profile/password', [
            'current_password' => 'not-the-password',
            'password' => 'a-brand-new-password',
            'password_confirm' => 'a-brand-new-password',
        ]);

        self::assertSame($before, $this->reload($this->memberId)->passwordHash);
    }

    public function testTheRightCurrentPasswordChangesItAndCanSignOutTheOthers(): void
    {
        $this->session->setId('this-browser');
        $this->givenSessionRows('this-browser', 'another-browser', 'a-third-browser');

        $this->signIn($this->memberId, keepId: true);

        $this->request('POST', '/profile/password', [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirm' => 'a-brand-new-password',
            'sign_out_others' => '1',
        ]);

        $member = $this->reload($this->memberId);
        self::assertTrue((new PasswordHasher())->verify('a-brand-new-password', $member->passwordHash));

        self::assertSame(
            ['this-browser'],
            $this->sessionIdsFor($this->memberId),
            'The browser making the change keeps its session; the others do not.',
        );
    }

    public function testTheOtherSessionsSurviveWhenTheBoxIsUnticked(): void
    {
        $this->session->setId('this-browser');
        $this->givenSessionRows('this-browser', 'another-browser');

        $this->signIn($this->memberId, keepId: true);

        $this->request('POST', '/profile/password', [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirm' => 'a-brand-new-password',
        ]);

        self::assertCount(2, $this->sessionIdsFor($this->memberId));
    }

    // ------------------------------------------------------------ the avatar

    public function testAnUploadedPictureIsStoredOutsideTheWebRootAndStreamsBack(): void
    {
        $this->signIn($this->memberId);

        $this->upload(FakeUpload::png('me.png'));

        $member = $this->reload($this->memberId);
        self::assertNotNull($member->avatarPath);

        $absolute = $this->avatarDirectory . '/' . $member->avatarPath;
        self::assertFileExists($absolute);
        self::assertStringNotContainsString(
            dirname(__DIR__, 2) . '/public',
            $absolute,
            'An avatar must not be reachable by the web server on its own.',
        );

        $response = $this->request('GET', '/avatars/' . $this->memberId);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        self::assertTrue($this->hasAudit(AuditAction::AvatarChanged));
    }

    public function testEveryStoredAvatarIsRedrawnAsPngWhateverArrived(): void
    {
        $this->signIn($this->memberId);

        // A GIF in, a PNG out: the stored file is one GD wrote from the pixels,
        // so nothing that was hiding in the original survives the round trip.
        $gif = imagecreatetruecolor(8, 8);
        ob_start();
        imagegif($gif);
        $bytes = (string) ob_get_clean();

        $this->upload(FakeUpload::of($bytes, 'me.gif', 'image/gif'));

        $member = $this->reload($this->memberId);
        self::assertNotNull($member->avatarPath);
        self::assertStringEndsWith('.png', $member->avatarPath);

        $info = getimagesize($this->avatarDirectory . '/' . $member->avatarPath);
        self::assertIsArray($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    public function testAFileThatIsNotAnImageIsRefusedHoweverItIsNamed(): void
    {
        $this->signIn($this->memberId);

        $this->upload(FakeUpload::of("<?php echo 'not a picture'; ?>", 'me.png', 'image/png'));

        self::assertNull($this->reload($this->memberId)->avatarPath);
    }

    public function testAnOversizedFileIsRefused(): void
    {
        $this->signIn($this->memberId);

        // Past the 2 MB ceiling, and genuinely so: the size is measured off
        // the file on disk, not off what the client declared.
        $this->upload(FakeUpload::of(str_repeat('A', 3 * 1024 * 1024), 'huge.png', 'image/png'));

        self::assertNull($this->reload($this->memberId)->avatarPath);
    }

    public function testAHousemateSeesTheFaceAndAnOutsiderGetsANotFound(): void
    {
        $this->signIn($this->memberId);
        $this->upload(FakeUpload::png('me.png'));

        $this->signIn($this->housemateId);
        self::assertSame(200, $this->request('GET', '/avatars/' . $this->memberId)->getStatusCode());

        $this->signIn($this->outsiderId);
        self::assertSame(
            404,
            $this->request('GET', '/avatars/' . $this->memberId)->getStatusCode(),
            'Somebody outside the household learns nothing, not even that the account exists.',
        );
    }

    public function testRemovingThePictureDeletesTheFileAndBringsBackTheInitials(): void
    {
        $this->signIn($this->memberId);
        $this->upload(FakeUpload::png('me.png'));

        $path = $this->reload($this->memberId)->avatarPath;
        self::assertNotNull($path);

        $this->request('POST', '/profile/avatar/delete');

        $member = $this->reload($this->memberId);
        self::assertNull($member->avatarPath);
        self::assertSame('MM', $member->initials());
        self::assertFileDoesNotExist($this->avatarDirectory . '/' . $path);
        self::assertSame(404, $this->request('GET', '/avatars/' . $this->memberId)->getStatusCode());

        self::assertTrue($this->hasAudit(AuditAction::AvatarRemoved));
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function settingsWithAvatarDirectory(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = (require dirname(__DIR__, 2) . '/config/settings.php');

        /** @var array<string, mixed> $uploads */
        $uploads = $settings['uploads'];
        $uploads['avatar_directory'] = $this->avatarDirectory;
        $settings['uploads'] = $uploads;

        return $settings;
    }

    private function reload(int $userId): \App\Domain\Entity\User
    {
        $user = (new UserRepository($this->db))->findById($userId);
        self::assertNotNull($user);

        return $user;
    }

    private function recipientsOf(int $index): string
    {
        $message = $this->mailer->messages[$index] ?? null;
        self::assertNotNull($message);

        return $message->toString();
    }

    private function givenSessionRows(string ...$ids): void
    {
        foreach ($ids as $id) {
            $this->db->insert('sessions', [
                'id' => $id,
                'user_id' => $this->memberId,
                'payload' => '',
                'last_activity' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'expires_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
            ], 'id');
        }
    }

    /**
     * @return list<string>
     */
    private function sessionIdsFor(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->q('id') . ' FROM ' . $this->q('sessions')
            . ' WHERE ' . $this->q('user_id') . ' = :user ORDER BY ' . $this->q('id'),
            ['user' => $userId],
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    private function hasAudit(AuditAction $action): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->q('audit_log')
            . ' WHERE ' . $this->q('action') . ' = :action AND ' . $this->q('actor_user_id') . ' = :actor',
            ['action' => $action->value, 'actor' => $this->memberId],
        ) !== null;
    }

    private function q(string $identifier): string
    {
        return $this->db->platform()->quoteIdentifier($identifier);
    }

    private function signIn(int $userId, bool $keepId = false): void
    {
        $id = $this->session->id();
        $this->session->clear();

        if ($keepId) {
            $this->session->setId($id);
        }

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function upload(UploadedFileInterface $file): ResponseInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/profile/avatar', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody([])
            ->withUploadedFiles(['avatar' => $file])
            ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());

        return $this->app->handle($request);
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
            $container = $this->app->getContainer();
            self::assertNotNull($container);

            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
