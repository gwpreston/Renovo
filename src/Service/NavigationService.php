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
     * The rail's main group: the three screens you read.
     *
     * Phase 9's rail had thirteen rows; the prototype has eight. Nothing lost
     * its route in the difference — each destination that no longer has a row
     * of its own is claimed by one that does, so the rail still says where you
     * are on it and there is still a way there. The claims are the `matches`
     * lists below, and the destination table in PHASE.md (section B) is the map they follow.
     *
     * The three of them carry `tab: true` and are what a phone gets along the
     * bottom, either side of the add button; the rest are behind "More".
     *
     * @return list<NavItem>
     */
    private function primaryItems(): array
    {
        return [
            new NavItem(
                id: 'dashboard',
                labelKey: 'nav.dashboard',
                href: '/',
                icon: 'dashboard',
                matches: ['/'],
                tab: true,
                tabLabelKey: 'nav.tab_home',
            ),
            // Cancel-by is the list read by one date, and the list links to
            // it; it lights the list rather than needing a row of its own.
            new NavItem(
                id: 'subscriptions',
                labelKey: 'nav.subscriptions',
                href: '/subscriptions',
                icon: 'subscriptions',
                matches: ['/subscriptions', '/cancellations'],
                permission: Permission::ViewSubscriptions,
                tab: true,
                tabLabelKey: 'nav.tab_subscriptions',
            ),
            // The forecast is the statistics' question asked forwards, so it
            // lights the same item; the statistics page links to it.
            new NavItem(
                id: 'analytics',
                labelKey: 'nav.analytics',
                href: '/stats',
                icon: 'analytics',
                matches: ['/stats', '/forecast'],
                permission: Permission::ViewSubscriptions,
                tab: true,
            ),
        ];
    }

    /**
     * The rail's "Household tools" group: the things around the list.
     *
     * Notifications sits beside Settings although it lives under it, because
     * it is per person (decision 13) and visited repeatedly. Its path is
     * longer than Settings', which is what makes it win the active item — see
     * `NavItem::claim()`. Members & roles wins over Settings the same way.
     *
     * Settings claims the screens that lost their rows — categories, payment
     * methods, import, the audit log — because its page carries the links to
     * them until the Settings rebuild gives them tabs.
     *
     * @return list<NavItem>
     */
    private function toolItems(?Scope $scope): array
    {
        return array_values(array_filter([
            new NavItem(
                id: 'budgets',
                labelKey: 'nav.budgets',
                href: '/budgets',
                icon: 'budget',
                matches: ['/budgets'],
                permission: Permission::ViewSubscriptions,
            ),
            new NavItem(
                id: 'calendar',
                labelKey: 'nav.calendar',
                href: '/calendar',
                icon: 'calendar',
                matches: ['/calendar'],
                permission: Permission::ViewSubscriptions,
            ),
            $this->membersItem($scope),
            new NavItem(
                id: 'notifications',
                labelKey: 'nav.notifications',
                href: '/settings/notifications',
                icon: 'notifications',
                matches: ['/settings/notifications'],
            ),
            new NavItem(
                id: 'settings',
                labelKey: 'nav.settings',
                href: '/settings',
                icon: 'settings',
                matches: ['/settings', '/import', '/categories', '/tags', '/payment-methods', '/audit'],
            ),
        ]));
    }

    /**
     * Members & roles, pointed at whichever of the two people screens this
     * reader may open — or nothing, if neither.
     *
     * An Owner/Admin gets the member screen, where the people are managed. An
     * Editor or Contributor may not manage anybody but may see the household's
     * read-only overview, which was a rail row of its own before this phase;
     * the same item takes them there, so the overview keeps its route. Both
     * paths light the one item wherever you arrived from.
     */
    private function membersItem(?Scope $scope): ?NavItem
    {
        $href = match (true) {
            $this->allows($scope, Permission::ManageHousehold) => '/settings/members',
            $this->allows($scope, Permission::ViewHousehold) => '/household',
            default => null,
        };

        if ($href === null) {
            return null;
        }

        return new NavItem(
            id: 'members',
            labelKey: 'nav.members',
            href: $href,
            icon: 'household',
            matches: ['/settings/members', '/household'],
        );
    }

    /**
     * The user card: what one account sets for itself, as against Settings,
     * where a household or an instance decides something on everybody's
     * behalf. It is drawn as a card rather than a row, but it is claimed like
     * any row, so standing on your profile lights it and nothing else.
     */
    private function accountItem(): NavItem
    {
        return new NavItem(
            id: 'profile',
            labelKey: 'nav.profile',
            href: '/profile',
            icon: 'profile',
            matches: ['/profile'],
        );
    }

    /**
     * The navigation for one request: this user's items, with the one that
     * describes this path marked active.
     */
    public function forPath(?Scope $scope, string $path): Navigation
    {
        $primary = $this->visible($this->primaryItems(), $scope);
        $tools = $this->visible($this->toolItems($scope), $scope);
        $account = $this->accountItem();

        $active = $this->activeItem([...$primary, ...$tools, $account], $path);

        $link = fn (NavItem $item): NavLink => new NavLink(
            $item->id,
            $item->labelKey,
            $item->href,
            $item->icon,
            $item === $active,
            $item->tabLabelKey ?? $item->labelKey,
        );

        $tabs = array_values(array_filter($primary, static fn (NavItem $item): bool => $item->tab));
        $drawer = array_values(array_filter(
            [...$primary, ...$tools],
            static fn (NavItem $item): bool => !$item->tab,
        ));

        return new Navigation(
            array_map($link, $primary),
            array_map($link, $tools),
            $link($account),
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

            return $this->allows($scope, $item->permission);
        }));
    }

    private function allows(?Scope $scope, Permission $permission): bool
    {
        return $scope !== null && $this->permissions->allows($scope, $permission);
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
