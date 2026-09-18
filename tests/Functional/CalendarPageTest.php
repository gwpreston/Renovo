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

    /**
     * A month inside the horizon with nothing in it. The fixture charges once
     * and never again, which is what makes such a month reachable at all — a
     * monthly subscription puts a charge in every month of the horizon, and
     * paging past the horizon clamps back to one that has one.
     */
    private const EMPTY_MONTH = '2026-11';

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
        // around it — the case the phone layout exists to collapse. It charges
        // once rather than monthly so that some other month of the horizon is
        // genuinely empty, which is a case of its own.
        (new SubscriptionRepository($this->db))->create(
            Scope::forMember($userId, true, $householdId, Role::OwnerAdmin, IsolationMode::Shared),
            [
                'name' => 'A subscription',
                'price_minor' => 999,
                'currency' => 'GBP',
                'subscription_type' => 'one_off',
                'billing_cycle' => null,
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

    /**
     * The rail and the grid are one swap.
     *
     * Month navigation replaces whatever it targets, so if the insight card
     * sat outside that target, paging to another month would leave it
     * describing the month the reader had just left — a card contradicting the
     * grid beside it, with nothing in the markup to say which was right.
     */
    public function testMonthNavigationSwapsTheRailAlongWithTheGrid(): void
    {
        $html = $this->calendar();

        self::assertStringContainsString('id="calendar-month"', $html);
        self::assertStringContainsString('calendar-insight', $html);
        self::assertStringContainsString('calendar-upcoming', $html);

        preg_match_all('/hx-target="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1], 'The month links no longer swap anything.');
        foreach ($matches[1] as $target) {
            self::assertSame('#calendar-month', $target);
        }
    }

    public function testTheFragmentCarriesTheRailRatherThanTheGridAlone(): void
    {
        // The htmx response is what a month link actually receives. A fragment
        // holding only the grid would swap the rail out of the page entirely.
        $html = $this->calendar(htmx: true);

        self::assertStringContainsString('id="calendar-month"', $html);
        self::assertStringContainsString('calendar-insight', $html);
        self::assertStringContainsString('calendar-upcoming', $html);
        // A fragment, not the whole page around it.
        self::assertStringNotContainsString('<body', $html);
    }

    /**
     * An empty month has to say so inside the grid card.
     *
     * The phone layout hides every quiet day, and in a month with nothing due
     * every day is quiet — so without this the card is a heading above nothing
     * at all, and the rail that would have explained it has stacked below the
     * fold.
     */
    public function testAMonthWithNothingDueSaysSoInsideTheGrid(): void
    {
        $html = $this->calendar(month: self::EMPTY_MONTH);

        $grid = $this->gridCard($html);
        self::assertStringContainsString('Nothing due this month', $grid);

        // And the rail's insight card is absent rather than an empty box: the
        // grid has already said it, better placed.
        self::assertStringNotContainsString('calendar-insight', $html);
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

    /**
     * The grid's own card, so an assertion about it cannot be satisfied by
     * something the rail happens to say.
     */
    private function gridCard(string $html): string
    {
        $start = strpos($html, '<section id="calendar"');
        self::assertNotFalse($start, 'The grid card is no longer in the page.');

        $end = strpos($html, '<aside', $start);

        return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
    }

    private function calendar(bool $htmx = false, ?string $month = null): string
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/calendar?month=' . ($month ?? self::MONTH),
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($htmx) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode(), 'The calendar did not render.');

        return (string) $response->getBody();
    }
}
