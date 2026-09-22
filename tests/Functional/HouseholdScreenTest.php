<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\HouseholdCapability;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\HouseholdOverviewService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The household screen: who is in it, and what each of them carries.
 *
 * Three things are worth a test here and the rest is layout.
 *
 * **The arithmetic.** A member's figures are their *share*, so a subscription
 * split down the middle has to appear on both cards at half its price and on
 * neither at its full one. The exact minor units are asserted against the
 * service rather than read out of the rendered page, because a page can only
 * be checked for the money it happens to have been formatted into and the
 * question here is whether the division is right.
 *
 * **Who may look.** The screen shows what other people spend, which is why it
 * is the one read in the application a Viewer is refused. The refusal is
 * asserted against the route, not against the absence of a link.
 *
 * **What isolation does to it.** In ISOLATED mode the scoped query returns the
 * caller's own rows, so every other member's total would be a fraction of the
 * truth. The screen has to say the figures are not shown rather than print a
 * number, and the dashboard's comparison has to disappear entirely.
 */
final class HouseholdScreenTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private ContainerInterface $container;

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

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $this->container = $container;

        $settings = $this->container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->setDemoMode(false);

        $users = $this->container->get(UserRepository::class);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('ada@example.test', 'Ada', 'hash', false, new DateTimeImmutable());
        $this->editorId = $users->create('bram@example.test', 'Bram', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('cleo@example.test', 'Cleo', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Ivy Cottage', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $subscriptions = new SubscriptionRepository($this->db);
        $ownerScope = $this->scopeFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        // £15 a month, halved between Ada and Bram.
        $shared = $subscriptions->create($ownerScope, [
            'name' => 'Family streaming',
            'price_minor' => 1500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        $this->container->get(PriceHistoryService::class)->recordInitialPrice(
            $ownerScope,
            $shared,
            Money::of(1500, 'GBP'),
            new DateTimeImmutable('2026-01-01'),
            $this->ownerId,
        );

        $this->container->get(SplitService::class)->update($ownerScope, $shared, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->ownerId => 1, $this->editorId => 1],
        ]);

        // £10 a month, Ada's alone.
        $subscriptions->create($ownerScope, [
            'name' => 'Ada notebook',
            'price_minor' => 1000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        // £4 a month, Bram's alone.
        $subscriptions->create(
            $this->scopeFor($this->editorId, Role::Editor, IsolationMode::Shared),
            [
                'name' => 'Bram music',
                'price_minor' => 400,
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
     * The division, in minor units.
     *
     * Ada carries half of the £15 plan and all of her own £10 one; Bram carries
     * the other half and all of his own £4 one; Cleo carries nothing. The
     * counts follow the same rule the money does, which is the point of
     * asserting both on the same row — a card claiming two subscriptions above
     * one subscription's worth of money would be two answers to one question.
     */
    public function testEachMemberCarriesTheirOwnShareAndNobodyElses(): void
    {
        $rows = $this->overviewFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        self::assertSame(['Ada', 'Bram', 'Cleo'], array_map(
            static fn (array $row): string => $row['member']->displayName,
            $rows,
        ));

        // Ada: 750 of the split plan + 1000 of her own.
        self::assertSame(2, $rows[0]['subscription_count']);
        self::assertSame(1750, $this->monthlyMinor($rows[0]));
        self::assertSame(21000, $this->yearlyMinor($rows[0]));

        // Bram: the other 750, plus his own 400.
        self::assertSame(2, $rows[1]['subscription_count']);
        self::assertSame(1150, $this->monthlyMinor($rows[1]));
        self::assertSame(13800, $this->yearlyMinor($rows[1]));

        // Cleo is on nothing. Not a missing card — a card saying nothing.
        self::assertSame(0, $rows[2]['subscription_count']);
        self::assertSame([], $rows[2]['monthly']['totals']);
    }

    /**
     * These halves add up to this whole: £15 + £10 + £4 is £29 a month, and the
     * three cards say £29 between them.
     *
     * Deliberately not stated as a general property, because it is not one. The
     * subscriptions here are billed monthly and split evenly, so every slice
     * divides exactly; the test below is the case where it does not, and the
     * service's docblock says which of the two is the guarantee.
     */
    public function testTheseSharesAddUpToWhatTheHouseholdSpends(): void
    {
        $rows = $this->overviewFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        $total = 0;
        foreach ($rows as $row) {
            $total += $this->monthlyMinor($row);
        }

        self::assertSame(2900, $total);
    }

    /**
     * What an awkward cycle does to the arithmetic, written down rather than
     * discovered later.
     *
     * `SplitService` makes the shares of a *price* sum to the price exactly.
     * The cards do not show a price, they show its monthly equivalent, and each
     * member's slice of that is rounded on its own — so £10 a year split three
     * ways is 83p a month for the household and 28p on each of three cards,
     * which is 84p. Each figure is the right answer to "what does this member
     * pay a month"; the sum is not re-derived from the household's total.
     *
     * The test exists so that the day somebody decides a penny matters, they
     * find the behaviour recorded and deliberate instead of arguing with a
     * report.
     */
    public function testAnOddCycleLeavesTheCardsAMinorUnitFromTheHouseholdTotal(): void
    {
        $ownerScope = $this->scopeFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        // £10 a year, split three ways: 334 / 333 / 333 of the price.
        $yearly = (new SubscriptionRepository($this->db))->create($ownerScope, [
            'name' => 'Odd cycle',
            'price_minor' => 1000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        $this->container->get(PriceHistoryService::class)->recordInitialPrice(
            $ownerScope,
            $yearly,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2026-01-01'),
            $this->ownerId,
        );

        $this->container->get(SplitService::class)->update($ownerScope, $yearly, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [
                $this->ownerId => 1,
                $this->editorId => 1,
                $this->viewerId => 1,
            ],
        ]);

        $rows = $this->overviewFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        // The household's own monthly figure for it is 83; each card carries 28
        // on top of what that member already had.
        self::assertSame(1750 + 28, $this->monthlyMinor($rows[0]));
        self::assertSame(1150 + 28, $this->monthlyMinor($rows[1]));
        self::assertSame(28, $this->monthlyMinor($rows[2]));

        $total = 0;
        foreach ($rows as $row) {
            $total += $this->monthlyMinor($row);
        }

        // 2900 + 84, where the household's own arithmetic would say 2900 + 83.
        self::assertSame(2984, $total);
    }

    /**
     * A Viewer is refused the route itself, not merely denied a link to it.
     */
    public function testAViewerIsRefusedTheScreen(): void
    {
        $this->signIn($this->viewerId);

        self::assertSame(403, $this->statusOf('/household'));
    }

    public function testAnEditorMaySeeIt(): void
    {
        $this->signIn($this->editorId);

        $html = $this->get('/household');

        self::assertStringContainsString('Ada', $html);
        self::assertStringContainsString('Bram', $html);
        self::assertStringContainsString('Cleo', $html);
    }

    /**
     * It reads and does not write.
     *
     * Asserted against the route table rather than against the markup: the
     * shell itself carries forms — signing out, searching — on every page, so
     * "no form on the screen" would be a claim about the frame rather than
     * about this screen. And asserted against the table rather than by posting
     * at the path, because a POST is turned away by the CSRF guard whether a
     * route is there to reach or not, which would pass either way.
     */
    public function testTheScreenChangesNothing(): void
    {
        $methods = [];
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            if ($route->getPattern() === '/household') {
                $methods = [...$methods, ...$route->getMethods()];
            }
        }

        self::assertSame(['GET'], $methods, 'Something other than a read is registered at /household.');

        $this->signIn($this->editorId);

        $editorHtml = $this->get('/household');

        self::assertStringNotContainsString('action="/household', $editorHtml);
        // The way through to the screen that *does* change things is an
        // Owner's alone, and is not rendered for anybody else.
        self::assertStringNotContainsString('/settings/members', $editorHtml);

        $this->signIn($this->ownerId);

        self::assertStringContainsString('href="/settings/members"', $this->get('/household'));
    }

    /**
     * The ladder is answered by `PermissionService`, so what a card says a
     * member can do is what the routes will actually let them do.
     *
     * Asserted as the whole ladder rather than as the rungs that happen to be
     * lit: every card lists all five, and "Cleo may not set budgets" is a claim
     * the screen makes out loud, not an absence.
     */
    public function testACardListsEveryRungAndMarksTheOnesTheRoleReallyHas(): void
    {
        $rows = $this->overviewFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        // The Owner: everything.
        self::assertSame([
            'see_everything' => true,
            'edit_own' => true,
            'edit_anything' => true,
            'set_budgets' => true,
            'manage_members' => true,
        ], $this->ladderOf($rows[0]));

        // The Editor: everything except the people.
        self::assertSame([
            'see_everything' => true,
            'edit_own' => true,
            'edit_anything' => true,
            'set_budgets' => true,
            'manage_members' => false,
        ], $this->ladderOf($rows[1]));

        // The Viewer: sight of the household, and nothing else.
        self::assertSame([
            'see_everything' => true,
            'edit_own' => false,
            'edit_anything' => false,
            'set_budgets' => false,
            'manage_members' => false,
        ], $this->ladderOf($rows[2]));
    }

    /**
     * A Contributor is the reason the ladder has two editing rungs.
     *
     * They read the whole household and write only their own part of it, which
     * is a sentence no other role can say and the pair of rungs exists to draw:
     * "See every line" lit, "Add and edit their own" lit, "Add and edit
     * anything" greyed. If the two ever collapsed into one, this is where it
     * would show.
     */
    public function testAContributorSeesEverythingAndEditsOnlyTheirOwn(): void
    {
        (new MembershipRepository($this->db))
            ->updateRole($this->householdId, $this->editorId, Role::Contributor);

        $rows = $this->overviewFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        self::assertSame([
            'see_everything' => true,
            'edit_own' => true,
            'edit_anything' => false,
            'set_budgets' => true,
            'manage_members' => false,
        ], $this->ladderOf($rows[1]));
    }

    /**
     * A Contributor is drawn controls for their own rows and not for others'.
     *
     * The bug this exists for is the one the role creates and no earlier test
     * could: for an Owner, an Editor and a Viewer, "may edit a subscription"
     * and "may edit *this* subscription" always agreed, so a screen asking the
     * permission alone was right by accident. A Contributor makes them differ,
     * and a Pause or Delete button drawn on Ada's row would be a control that
     * answers 404 for using it.
     */
    public function testAContributorIsOnlyOfferedControlsForTheirOwnRows(): void
    {
        (new MembershipRepository($this->db))
            ->updateRole($this->householdId, $this->editorId, Role::Contributor);

        $this->signIn($this->editorId);

        $html = $this->get('/subscriptions');

        // Both rows are on the page — a Contributor reads the whole household.
        self::assertStringContainsString('Ada notebook', $html);
        self::assertStringContainsString('Bram music', $html);

        // Their own row carries the controls; Ada's does not.
        self::assertStringContainsString(
            sprintf('action="/subscriptions/%d/delete"', $this->bramsOwnRow()),
            $html,
        );
        self::assertStringNotContainsString(
            sprintf('action="/subscriptions/%d/delete"', $this->adasOwnRow()),
            $html,
            'A Contributor was offered a Delete button on somebody else\'s row.',
        );

        // And the form behind it is refused rather than rendered and then
        // failed on save.
        self::assertSame(404, $this->statusOf('/subscriptions/' . $this->adasOwnRow() . '/edit'));
        self::assertSame(200, $this->statusOf('/subscriptions/' . $this->bramsOwnRow() . '/edit'));
    }

    /**
     * Isolation reaches the ladder too, and it reaches an Owner's.
     *
     * The scoping layer restricts reads and writes to a member's own rows for
     * every role, so in ISOLATED nobody sees every line and nobody edits
     * anybody else's. A card that still claimed an Owner could would be
     * describing a power the repository refuses — the same lie the withheld
     * figures exist to avoid, told about permissions instead of money.
     */
    public function testIsolationGreysTheTwoRungsThatReachOtherPeoplesRows(): void
    {
        $rows = $this->overviewFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Isolated);

        self::assertSame([
            'see_everything' => false,
            'edit_own' => true,
            'edit_anything' => false,
            'set_budgets' => true,
            'manage_members' => true,
        ], $this->ladderOf($rows[0]));
    }

    /**
     * The greying is not carried by colour alone.
     */
    public function testAnAbsentRungSaysSoInWordsAsWellAsInGrey(): void
    {
        $html = $this->get('/household');

        self::assertStringContainsString('Add and edit anything', $html);
        self::assertStringContainsString('capability is-absent', $html);
        // The word a screen reader gets where a sighted reader gets the grey.
        self::assertStringContainsString('visually-hidden', $html);
    }

    /**
     * ISOLATED mode: your own figures, and an honest silence about everybody
     * else's.
     *
     * The scoped query hands back the caller's rows and the splits they are on,
     * so Bram's total computed from an Owner's ISOLATED scope would be whatever
     * fraction of it happened to be visible. The card says so instead.
     */
    public function testIsolationWithholdsOtherMembersFiguresRatherThanHalvingThem(): void
    {
        $this->container->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $rows = $this->overviewFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Isolated);

        // Ada still sees her own, in full.
        self::assertFalse($rows[0]['figures_withheld']);
        self::assertSame(1750, $this->monthlyMinor($rows[0]));

        // Bram and Cleo are blank rather than wrong. Bram genuinely carries
        // £11.50 a month; the number reachable from here would be £7.50, and
        // printing that would be worse than printing nothing.
        //
        // The flag is what says so. A withheld row's count and figures are not
        // "nothing spent" but "not counted", which is why both templates ask
        // about the flag before rendering either — and why this asserts the
        // flag rather than the empty number sitting behind it.
        self::assertTrue($rows[1]['figures_withheld']);
        self::assertTrue($rows[2]['figures_withheld']);

        $html = $this->get('/household');

        self::assertStringContainsString('Not shown', $html);
        self::assertStringContainsString('Bram', $html, 'The roster is still the roster.');
    }

    /**
     * The dashboard card is a comparison, and it is drawn only when there is
     * one to make.
     */
    public function testTheDashboardCardComparesMembersOnlyWhenItHonestlyCan(): void
    {
        self::assertStringContainsString('Who pays for what', $this->get('/'));

        // A Viewer is not shown on the dashboard what they are refused on its
        // own screen. The rows are never built, so there is nothing for the
        // template to forget to hide.
        $this->signIn($this->viewerId);
        self::assertStringNotContainsString('Who pays for what', $this->get('/'));

        // And in ISOLATED mode there is one member with figures — the reader —
        // which is not a comparison.
        $this->container->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);
        $this->signIn($this->ownerId);
        self::assertStringNotContainsString('Who pays for what', $this->get('/'));
    }

    private function adasOwnRow(): int
    {
        return $this->rowNamed('Ada notebook');
    }

    private function bramsOwnRow(): int
    {
        return $this->rowNamed('Bram music');
    }

    private function rowNamed(string $name): int
    {
        foreach (
            (new SubscriptionRepository($this->db))->findAllForStats(
                $this->scopeFor($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared),
            ) as $subscription
        ) {
            if ($subscription->name === $name) {
                return $subscription->id;
            }
        }

        self::fail('No subscription named ' . $name);
    }

    /**
     * One member's ladder as capability key => whether they are on that rung.
     *
     * @param array<string, mixed> $row
     * @return array<string, bool>
     */
    private function ladderOf(array $row): array
    {
        /** @var list<array{capability: HouseholdCapability, held: bool}> $rungs */
        $rungs = $row['capabilities'];

        $ladder = [];
        foreach ($rungs as $rung) {
            $ladder[$rung['capability']->value] = $rung['held'];
        }

        return $ladder;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function monthlyMinor(array $row): int
    {
        /** @var array{combined: array{amount_minor: int|null}} $figures */
        $figures = $row['monthly'];

        return $figures['combined']['amount_minor'] ?? 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function yearlyMinor(array $row): int
    {
        /** @var array{combined: array{amount_minor: int|null}} $figures */
        $figures = $row['yearly'];

        return $figures['combined']['amount_minor'] ?? 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function overviewFor(int $userId, Role $role, IsolationMode $mode): array
    {
        return $this->container->get(HouseholdOverviewService::class)
            ->members($this->scopeFor($userId, $role, $mode));
    }

    private function scopeFor(int $userId, Role $role, IsolationMode $mode): Scope
    {
        return Scope::forMember($userId, false, $this->householdId, $role, $mode);
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function get(string $path): string
    {
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

    private function statusOf(string $path): int
    {
        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost' . $path,
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        )->getStatusCode();
    }
}
