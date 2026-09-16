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
 * What the calendar page has to put in its markup for the stylesheet to have
 * anything to work with.
 *
 * The phone layout is CSS, and CSS cannot be asserted on — but it is CSS that
 * depends on the template: it hides `.is-quiet` and prints `data-weekday`, and
 * neither exists unless this page emits it. A template edit that drops either
 * leaves a column of thirty bare numbers on a phone and nothing anywhere else
 * to notice, which is what these tests are for.
 */
final class CalendarPageTest extends DatabaseTestCase
{
    /** The month the fixtures live in, so the assertions do not drift. */
    private const MONTH = '2026-09';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

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

        $userId = $users->create('owner@example.test', 'Owner', 'hash', true, new DateTimeImmutable());
        $householdId = $households->create('Household', $userId);
        $memberships->create($householdId, $userId, Role::OwnerAdmin);

        // One charge, on a day chosen so the month has plenty of quiet days
        // around it — the case the phone layout exists to collapse.
        (new SubscriptionRepository($this->db))->create(
            Scope::forMember($userId, true, $householdId, Role::OwnerAdmin, IsolationMode::Shared),
            [
                'name' => 'A subscription',
                'price_minor' => 999,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => self::MONTH . '-18',
                'is_active' => true,
            ],
            [],
        );

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $householdId);
    }

    public function testEveryDayNamesItsOwnWeekday(): void
    {
        $days = $this->dayCells($this->calendar());

        self::assertCount(30, $days, 'September has thirty days.');

        foreach ($days as $cell) {
            self::assertMatchesRegularExpression(
                '/data-weekday="[^"]+"/',
                $cell,
                'A day with no weekday is a bare number once the column headings are gone.',
            );
        }
    }

    public function testADayWithNothingDueIsMarkedQuiet(): void
    {
        $days = $this->dayCells($this->calendar());

        $quiet = array_filter($days, static fn (string $cell): bool => str_contains($cell, 'is-quiet'));
        $busy = array_filter($days, static fn (string $cell): bool => !str_contains($cell, 'is-quiet'));

        // One charge on the 18th, so exactly one day has something on it.
        self::assertCount(1, $busy);
        self::assertCount(29, $quiet);

        self::assertStringContainsString('A subscription', reset($busy));
        foreach ($quiet as $cell) {
            self::assertStringNotContainsString('calendar-events', $cell);
        }
    }

    public function testThePaddingCellsStayDistinctFromQuietDays(): void
    {
        $html = $this->calendar();

        // `is-empty` is a neighbouring month and has no date at all; `is-quiet`
        // is a real day of this one. The phone layout hides both, the desktop
        // grid draws only the second, so they cannot be the same class.
        self::assertStringContainsString('calendar-day is-empty', $html);
        self::assertDoesNotMatchRegularExpression('/is-empty[^"]*is-quiet/', $html);
    }

    public function testStylesheetsAndScriptsAreVersioned(): void
    {
        $html = $this->calendar();

        // Without this the page can be served against a stylesheet cached
        // before the markup existed, which is how the grid came to render
        // unstyled in the first place.
        preg_match_all('#(?:href|src)="(/assets/[^"]+)"#', $html, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $url) {
            self::assertMatchesRegularExpression('/\?v=[0-9a-f]+$/', $url, $url . ' is not cache-busted.');
        }
    }

    /**
     * The `<td>` of every real day, padding cells excluded.
     *
     * @return list<string>
     */
    private function dayCells(string $html): array
    {
        preg_match_all('#<td class="calendar-day(?!\s+is-empty).*?</td>#s', $html, $matches);

        return $matches[0];
    }

    private function calendar(): string
    {
        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost/calendar?month=' . self::MONTH,
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );

        self::assertSame(200, $response->getStatusCode(), 'The calendar did not render.');

        return (string) $response->getBody();
    }
}
