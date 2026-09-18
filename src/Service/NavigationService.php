<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\NavItem;
use App\Domain\NavLink;
use App\Domain\Navigation;
use App\Domain\Permission;
use App\Security\PermissionService;
use App\Security\Scope;

/**
 * What the shell can reach, and which item is the page you are on.
 *
 * Every destination in the application is declared once, here. The rail renders
 * it, the narrow screen's tab bar and drawer render it, and the keyboard
 * shortcuts go to the same places — so there is no arrangement of screen width
 * or permission in which a page exists and nothing links to it.
 *
 * Two things this deliberately does not do. It does not enforce anything: an
 * item is hidden from somebody who may not use it because showing them a link
 * that answers 403 is unkind, and `RequirePermissionMiddleware` is what actually
 * stops them. And it holds no figures — the shell is a frame, and a frame has
 * nothing to be wrong about.
 */
final class NavigationService
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /**
     * The rail's main group: the application's own screens.
     *
     * The order is the order the design gives, with the destinations the design
     * table has no row for kept rather than dropped — a redesign that quietly
     * removed the only link to budgets would be a regression dressed as a
     * layout. Four of them carry `tab: true` and are what a phone gets along
     * the bottom; the rest are one tap further away behind "More".
     *
     * @return list<NavItem>
     */
    private function primaryItems(): array
    {
        return [
            new NavItem(
                labelKey: 'nav.dashboard',
                href: '/',
                icon: 'dashboard',
                matches: ['/'],
                tab: true,
            ),
            new NavItem(
                labelKey: 'nav.subscriptions',
                href: '/subscriptions',
                icon: 'subscriptions',
                matches: ['/subscriptions'],
                permission: Permission::ViewSubscriptions,
                tab: true,
            ),
            // The design calls this Analytics and points it at the statistics
            // screen; the forecast is the same question asked forwards, so it
            // lights the same item rather than needing a rail entry of its own.
            new NavItem(
                labelKey: 'nav.analytics',
                href: '/stats',
                icon: 'analytics',
                matches: ['/stats', '/forecast'],
                permission: Permission::ViewSubscriptions,
                tab: true,
            ),
            new NavItem(
                labelKey: 'nav.calendar',
                href: '/calendar',
                icon: 'calendar',
                matches: ['/calendar'],
                permission: Permission::ViewSubscriptions,
                tab: true,
            ),
            new NavItem(
                labelKey: 'nav.budgets',
                href: '/budgets',
                icon: 'budget',
                matches: ['/budgets'],
                permission: Permission::ViewSubscriptions,
            ),
            new NavItem(
                labelKey: 'nav.cancellations',
                href: '/cancellations',
                icon: 'cancellations',
                matches: ['/cancellations'],
                permission: Permission::ViewSubscriptions,
            ),
            new NavItem(
                labelKey: 'nav.categories',
                href: '/categories',
                icon: 'categories',
                matches: ['/categories'],
                permission: Permission::ViewSubscriptions,
            ),
        ];
    }

    /**
     * The rail's bottom group: the things you configure rather than read.
     *
     * Notifications is above Settings although it lives under it, because the
     * design gives it a row of its own and because it is the one a person
     * visits repeatedly. Its path is longer than Settings', which is what makes
     * it win the active item — see `NavItem::claim()`.
     *
     * @return list<NavItem>
     */
    private function toolItems(): array
    {
        return [
            new NavItem(
                labelKey: 'nav.notifications',
                href: '/settings/notifications',
                icon: 'notifications',
                matches: ['/settings/notifications'],
            ),
            new NavItem(
                labelKey: 'nav.import',
                href: '/import',
                icon: 'import',
                matches: ['/import'],
                permission: Permission::ImportData,
            ),
            new NavItem(
                labelKey: 'nav.audit',
                href: '/audit',
                icon: 'audit',
                matches: ['/audit'],
                permission: Permission::ViewAuditLog,
            ),
            new NavItem(
                labelKey: 'nav.settings',
                href: '/settings',
                icon: 'settings',
                matches: ['/settings'],
            ),
            // Profile is a page of its own: what one account sets for itself,
            // as against Settings, where a household or an instance decides
            // something on everybody's behalf.
            new NavItem(
                labelKey: 'nav.profile',
                href: '/profile',
                icon: 'profile',
                matches: ['/profile'],
            ),
        ];
    }

    /**
     * The navigation for one request: this user's items, with the one that
     * describes this path marked active.
     */
    public function forPath(?Scope $scope, string $path): Navigation
    {
        $primary = $this->visible($this->primaryItems(), $scope);
        $tools = $this->visible($this->toolItems(), $scope);

        $active = $this->activeItem([...$primary, ...$tools], $path);

        $link = fn (NavItem $item): NavLink => new NavLink(
            $item->labelKey,
            $item->href,
            $item->icon,
            $item === $active,
        );

        $tabs = array_values(array_filter($primary, static fn (NavItem $item): bool => $item->tab));
        $drawer = array_values(array_filter(
            [...$primary, ...$tools],
            static fn (NavItem $item): bool => !$item->tab,
        ));

        return new Navigation(
            array_map($link, $primary),
            array_map($link, $tools),
            array_map($link, $tabs),
            array_map($link, $drawer),
        );
    }

    /**
     * The items this user may use.
     *
     * A null scope is what an error page rendered outside a session hands in.
     * It sees only the items that ask for nothing, which is the same answer as
     * "signed in with no household": true, and not a crash.
     *
     * @param list<NavItem> $items
     * @return list<NavItem>
     */
    private function visible(array $items, ?Scope $scope): array
    {
        return array_values(array_filter($items, function (NavItem $item) use ($scope): bool {
            if ($item->permission === null) {
                return true;
            }

            return $scope !== null && $this->permissions->allows($scope, $item->permission);
        }));
    }

    /**
     * The single item this path belongs to, or null if it belongs to none.
     *
     * The most specific claim wins, so `/settings/notifications` lights
     * Notifications rather than Settings, while everything under a section —
     * `/subscriptions/12/edit`, `/budgets/new` — lights the section it is part
     * of. A path nothing claims lights nothing rather than guessing.
     *
     * @param list<NavItem> $items
     */
    private function activeItem(array $items, string $path): ?NavItem
    {
        $active = null;
        $best = 0;

        foreach ($items as $item) {
            $claim = $item->claim($path);

            if ($claim !== null && $claim > $best) {
                $active = $item;
                $best = $claim;
            }
        }

        return $active;
    }
}
