<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\IsolationMode;
use App\Domain\NavLink;
use App\Domain\Navigation;
use App\Domain\Role;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Service\NavigationService;
use PHPUnit\Framework\TestCase;

/**
 * Which navigation item a page belongs to, and who can see which items.
 *
 * The shell's whole job is to be right about where you are, and the way that
 * goes wrong is prefixes: `/settings` is the start of `/settings/notifications`
 * and `/` is the start of everything. So the interesting assertion is not
 * "Settings is active on the settings page" but "exactly one item is, and it is
 * that one" — which is what this makes of every path the application serves.
 */
final class NavigationTest extends TestCase
{
    private NavigationService $navigation;

    protected function setUp(): void
    {
        $this->navigation = new NavigationService(new PermissionService());
    }

    /**
     * Every path the shell can be rendered on, and the item it belongs to.
     *
     * A null expectation means no item claims it: a page that is reached from
     * somewhere rather than navigated to.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function paths(): array
    {
        return [
            'the dashboard' => ['/', 'nav.dashboard'],
            'the list' => ['/subscriptions', 'nav.subscriptions'],
            'a new subscription' => ['/subscriptions/new', 'nav.subscriptions'],
            'editing one' => ['/subscriptions/12/edit', 'nav.subscriptions'],
            'its money' => ['/subscriptions/12/money', 'nav.subscriptions'],
            'statistics' => ['/stats', 'nav.analytics'],
            'the forecast, which is analytics too' => ['/forecast', 'nav.analytics'],
            'the calendar' => ['/calendar', 'nav.calendar'],
            'budgets' => ['/budgets', 'nav.budgets'],
            'a new budget' => ['/budgets/new', 'nav.budgets'],
            'cancel-by' => ['/cancellations', 'nav.cancellations'],
            'categories' => ['/categories', 'nav.categories'],
            'the household' => ['/household', 'nav.household'],
            'settings' => ['/settings', 'nav.settings'],
            'your own page' => ['/profile', 'nav.profile'],
            // The three below all start with "/settings" and only one of them
            // is Notifications. This is the collision the old hand-written
            // navigation had to special-case in the template.
            'alerts, which are not settings' => ['/settings/notifications', 'nav.notifications'],
            'security is settings' => ['/settings/security', 'nav.settings'],
            'api tokens are settings' => ['/settings/api-tokens', 'nav.settings'],
            'backup is settings' => ['/settings/backup', 'nav.settings'],
            // Import has no rail row of its own; Settings, which carries the
            // button that leads there, is what stays lit while you use it.
            'import is settings' => ['/import', 'nav.settings'],
            'the import mapping step' => ['/import/map', 'nav.settings'],
            'the audit log' => ['/audit', 'nav.audit'],
            // Deliberately claimed by nothing: a path that merely begins with
            // the same letters as a section is not part of it.
            'a path that only looks like the list' => ['/subscriptions-archive', null],
        ];
    }

    /**
     * @dataProvider paths
     */
    public function testExactlyOneItemClaimsEachPath(string $path, ?string $expected): void
    {
        $navigation = $this->navigation->forPath($this->owner(), $path);

        $active = $this->activeLabels($navigation);

        self::assertSame(
            $expected === null ? [] : [$expected],
            $active,
            sprintf('%s should be %s.', $path, $expected ?? 'claimed by no navigation item'),
        );
    }

    /**
     * The root does not claim every page in the application.
     *
     * Every path begins with "/", so a prefix test written the obvious way
     * lights the dashboard everywhere — and then lights the real item beside
     * it, because both matched.
     */
    public function testTheDashboardClaimsOnlyTheDashboard(): void
    {
        self::assertSame(['nav.dashboard'], $this->activeLabels($this->navigation->forPath($this->owner(), '/')));
        self::assertSame(
            ['nav.calendar'],
            $this->activeLabels($this->navigation->forPath($this->owner(), '/calendar')),
        );
    }

    /**
     * Profile and Settings are two pages, and the rail lights one of them at a
     * time. Standing on Settings must not light Profile as well.
     */
    public function testProfileIsItsOwnDestination(): void
    {
        $tools = $this->navigation->forPath($this->owner(), '/settings')->tools;

        $profile = $this->byLabel($tools, 'nav.profile');

        self::assertNotNull($profile, 'The profile link is gone.');
        self::assertSame('/profile', $profile->href);
        self::assertFalse($profile->active, 'Settings is the page; Profile is not.');

        $onProfile = $this->navigation->forPath($this->owner(), '/profile')->tools;

        self::assertTrue($this->byLabel($onProfile, 'nav.profile')?->active);
        self::assertFalse($this->byLabel($onProfile, 'nav.settings')?->active);
    }

    /**
     * A Viewer sees the screens they can read and not the ones that would
     * answer 403. Hiding them is a courtesy; the middleware is the control.
     */
    public function testAViewerIsNotOfferedWhatTheyMayNotDo(): void
    {
        $labels = $this->allLabels($this->navigation->forPath($this->viewer(), '/'));

        self::assertContains('nav.subscriptions', $labels);
        self::assertContains('nav.settings', $labels);
        self::assertNotContains('nav.audit', $labels, 'A Viewer cannot read the audit log.');
    }

    /**
     * An instance administrator with no household of their own gets the items
     * that need no household, and none of the ones that would be empty.
     */
    public function testSomebodyWithNoHouseholdIsOfferedOnlyWhatExists(): void
    {
        $labels = $this->allLabels($this->navigation->forPath(
            Scope::withoutHousehold(3, true, IsolationMode::Shared),
            '/settings',
        ));

        self::assertSame(['nav.dashboard', 'nav.notifications', 'nav.audit', 'nav.settings', 'nav.profile'], $labels);
    }

    /**
     * The narrow screen reaches everything the rail does.
     *
     * The tab bar and the drawer are a split of the same list, so this is the
     * assertion that a destination cannot be added to the rail and quietly not
     * exist on a phone.
     */
    public function testTheNarrowLayoutReachesEveryDestinationTheRailDoes(): void
    {
        $navigation = $this->navigation->forPath($this->owner(), '/');

        $rail = array_map(static fn (NavLink $link): string => $link->href, [
            ...$navigation->primary,
            ...$navigation->tools,
        ]);

        $narrow = array_map(static fn (NavLink $link): string => $link->href, [
            ...$navigation->tabs,
            ...$navigation->drawer,
        ]);

        sort($rail);
        sort($narrow);

        self::assertSame($rail, $narrow);
    }

    /**
     * The disclosure that hides half the destinations says when the page you
     * are on is one of them, so a narrow screen is not silent about where it
     * is until somebody opens it.
     */
    public function testTheDrawerSaysWhenItHoldsThePageYouAreOn(): void
    {
        self::assertTrue($this->navigation->forPath($this->owner(), '/settings')->drawerHoldsActive());
        self::assertTrue($this->navigation->forPath($this->owner(), '/profile')->drawerHoldsActive());
        self::assertFalse($this->navigation->forPath($this->owner(), '/')->drawerHoldsActive());
    }

    private function owner(): Scope
    {
        return Scope::forMember(1, false, 1, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function viewer(): Scope
    {
        return Scope::forMember(2, false, 1, Role::Viewer, IsolationMode::Shared);
    }

    /**
     * @param list<NavLink> $links
     */
    private function byLabel(array $links, string $labelKey): ?NavLink
    {
        foreach ($links as $link) {
            if ($link->labelKey === $labelKey) {
                return $link;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function activeLabels(Navigation $navigation): array
    {
        return array_values(array_map(
            static fn (NavLink $link): string => $link->labelKey,
            array_filter(
                [...$navigation->primary, ...$navigation->tools],
                static fn (NavLink $link): bool => $link->active,
            ),
        ));
    }

    /**
     * @return list<string>
     */
    private function allLabels(Navigation $navigation): array
    {
        return array_map(
            static fn (NavLink $link): string => $link->labelKey,
            [...$navigation->primary, ...$navigation->tools],
        );
    }
}
