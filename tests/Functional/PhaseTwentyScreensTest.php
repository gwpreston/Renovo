<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
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
 * The controls Phase 20 adds to the *current* pages — no redesign, just enough
 * for each addition to be usable: a plan field and a visibility choice on the
 * form, cancel and undo on the list, a status filter, a subject picker on the
 * budget form, the price-change toggle, and the backup screen's statement.
 */
final class PhaseTwentyScreensTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
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

        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new DateTimeImmutable();
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, $now);
        $this->householdId = (new HouseholdRepository($this->db))->create('House', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->signIn($this->ownerId);
    }

    public function testThePlanAndVisibilityAreSavedFromTheForm(): void
    {
        $response = $this->request('POST', '/subscriptions', $this->form([
            'name' => 'Streaming',
            'plan' => 'Family',
            'visibility' => 'payer',
        ]));
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        $row = $this->onlySubscription();
        self::assertSame('Family', $row->plan);
        self::assertTrue($row->isPrivate());

        $edit = (string) $this->request('GET', '/subscriptions/' . $row->id . '/edit')->getBody();
        self::assertStringContainsString('value="Family"', $edit);
        self::assertMatchesRegularExpression('/value="payer"\s+checked/', $edit);

        $list = (string) $this->request('GET', '/subscriptions')->getBody();
        self::assertStringContainsString('Family', $list);
        self::assertStringContainsString('Only me', $list);
    }

    public function testASplitRowCannotChooseOnlyMeAndAPrivateRowCannotBeSplit(): void
    {
        $this->request('POST', '/subscriptions', $this->form(['name' => 'Shared']));
        $shared = $this->onlySubscription();
        $this->container()->get(SplitService::class)->update($this->ownerScope(), $shared->id, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->ownerId => 1, $this->editorId => 1],
        ]);

        $edit = (string) $this->request('GET', '/subscriptions/' . $shared->id . '/edit')->getBody();
        self::assertMatchesRegularExpression('/value="payer"[^>]*disabled/', $edit);

        $this->request('POST', '/subscriptions', $this->form(['name' => 'Mine', 'visibility' => 'payer']));
        $private = null;
        foreach ($this->all() as $subscription) {
            if ($subscription->name === 'Mine') {
                $private = $subscription;
            }
        }
        self::assertNotNull($private);

        $money = (string) $this->request('GET', '/subscriptions/' . $private->id . '/money')->getBody();
        self::assertStringContainsString('data-split-closed', $money);
        self::assertStringNotContainsString('name="split_mode"', $money);
    }

    public function testTheListOffersCancelAndUndoInTheCatalogueWords(): void
    {
        $this->request('POST', '/subscriptions', $this->form(['name' => 'Streaming']));
        $id = $this->onlySubscription()->id;

        $list = (string) $this->request('GET', '/subscriptions')->getBody();
        self::assertStringContainsString('/subscriptions/' . $id . '/cancel', $list);
        self::assertStringContainsString('Pause', $list);

        $this->request('POST', '/subscriptions/' . $id . '/cancel');

        // Cancelled rows leave the default list and are found under their own
        // filter, with the way back offered.
        $default = (string) $this->request('GET', '/subscriptions')->getBody();
        self::assertStringNotContainsString('/subscriptions/' . $id . '/uncancel', $default);

        $cancelled = (string) $this->request('GET', '/subscriptions?status=cancelled')->getBody();
        self::assertStringContainsString('/subscriptions/' . $id . '/uncancel', $cancelled);
        self::assertStringContainsString('data-status="cancelled"', $cancelled);

        $this->request('POST', '/subscriptions/' . $id . '/uncancel');
        $paused = (string) $this->request('GET', '/subscriptions?status=paused')->getBody();
        self::assertStringContainsString('data-status="paused"', $paused);
    }

    public function testAViewerIsRefusedCancelOnTheWeb(): void
    {
        $this->request('POST', '/subscriptions', $this->form(['name' => 'Streaming']));
        $id = $this->onlySubscription()->id;

        $this->signIn($this->viewerId);
        self::assertSame(403, $this->request('POST', '/subscriptions/' . $id . '/cancel')->getStatusCode());

        $this->signIn($this->ownerId);
        self::assertNull($this->onlySubscription()->cancelledAt);
    }

    public function testTheBudgetFormOffersEveryMemberAndTheHousehold(): void
    {
        $form = (string) $this->request('GET', '/budgets/new')->getBody();

        self::assertStringContainsString('name="subject_user_id"', $form);
        self::assertStringContainsString('value="household"', $form);
        self::assertStringContainsString('value="' . $this->editorId . '"', $form);

        $this->request('POST', '/budgets', [
            'name' => 'Editor',
            'period' => 'monthly',
            'amount' => '50.00',
            'currency' => 'GBP',
            'subject_user_id' => (string) $this->editorId,
        ]);

        $index = (string) $this->request('GET', '/budgets')->getBody();
        self::assertStringContainsString('Editor', $index);
    }

    public function testTheBudgetFormHasNoPickerInIsolatedMode(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        self::assertStringNotContainsString(
            'name="subject_user_id"',
            (string) $this->request('GET', '/budgets/new')->getBody(),
        );
    }

    public function testThePriceChangeToggleSavesFromTheNotificationsPage(): void
    {
        $page = (string) $this->request('GET', '/settings/notifications')->getBody();
        self::assertMatchesRegularExpression('/name="price_change_alerts" value="1"[^>]*checked/', $page);

        $this->request('POST', '/settings/notifications/preferences', [
            'lead_days' => '30, 7, 1',
            'digest_mode' => 'immediate',
            'digest_day' => '1',
            'price_change_alerts' => '0',
        ]);

        $page = (string) $this->request('GET', '/settings/notifications')->getBody();
        self::assertDoesNotMatchRegularExpression('/name="price_change_alerts" value="1"[^>]*checked/', $page);
    }

    public function testTheBackupScreenSaysHowManyPrivateSubscriptionsItLeavesOut(): void
    {
        $this->signIn($this->editorId);
        $this->request('POST', '/subscriptions', $this->form(['name' => 'Mine', 'visibility' => 'payer']));

        $this->signIn($this->ownerId);
        $page = (string) $this->request('GET', '/settings/backup')->getBody();

        self::assertStringContainsString('data-private-left-out="1"', $page);
        self::assertStringContainsString('1 subscription that another member keeps to themselves', $page);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function form(array $overrides): array
    {
        return $overrides + [
            'name' => 'Streaming',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'is_active' => '1',
            'visibility' => 'household',
        ];
    }

    private function onlySubscription(): \App\Domain\Entity\Subscription
    {
        $all = $this->all();
        self::assertCount(1, $all);

        return $all[0];
    }

    /**
     * @return list<\App\Domain\Entity\Subscription>
     */
    private function all(): array
    {
        return $this->container()->get(SubscriptionService::class)->allForStats($this->ownerScope(), activeOnly: false);
    }

    private function ownerScope(): Scope
    {
        return Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        if ($method !== 'GET') {
            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
