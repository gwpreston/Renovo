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

        $this->subscriptionId = (new SubscriptionRepository($this->db))->create(
            Scope::forMember($this->userId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared),
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
            ['/profile'],
            ['/settings'],
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
