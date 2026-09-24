<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One destination in the application's navigation.
 *
 * The catalogue these are declared in — `NavigationService::CATALOGUE` — is the
 * single definition of what the shell can reach. The rail, the narrow screen's
 * tab bar and the drawer behind it are three projections of that one list, so a
 * destination cannot exist in the rail and be missing from the phone.
 *
 * `matches` is what decides the active item, and it is a list of paths rather
 * than a prefix test at the call site because prefixes collide: `/settings`
 * is the start of `/settings/notifications`, which is a different destination.
 * Longest match wins, and a match is a whole path segment — `/subscriptions`
 * matches `/subscriptions/12/edit` and not `/subscriptions-archive`.
 *
 * An item with no `matches` at all is reachable and never highlighted, which is
 * what an item that only links somewhere else wants.
 */
final class NavItem
{
    /**
     * @param non-empty-string $labelKey   Catalogue key for the visible label.
     * @param non-empty-string $href       Where the item goes.
     * @param non-empty-string $icon       A key of `assets/theme/icons.json`.
     * @param list<string> $matches        Paths for which this item is the active one.
     * @param Permission|null $permission  What a user must be allowed to do to see it.
     * @param bool $tab                    Whether it is one of the narrow screen's tabs.
     */
    public function __construct(
        public readonly string $labelKey,
        public readonly string $href,
        public readonly string $icon,
        public readonly array $matches = [],
        public readonly ?Permission $permission = null,
        public readonly bool $tab = false,
    ) {
    }

    /**
     * How specifically this item claims the given path, or null for not at all.
     *
     * The number is the length of the matched path, which is what lets the
     * caller pick between two items that both claim it: `/settings/notifications`
     * is claimed by Notifications at 23 characters and by Settings at 9, and
     * the longer claim is the more specific one.
     */
    public function claim(string $path): ?int
    {
        $best = null;

        foreach ($this->matches as $match) {
            // Empty for the root, which is why it is tested separately: every
            // path starts with "/", so a prefix test would hand the dashboard
            // every page in the application.
            $prefix = rtrim($match, '/');

            if ($path === $match || ($prefix !== '' && str_starts_with($path, $prefix . '/'))) {
                $best = max($best ?? 0, strlen($match));
            }
        }

        return $best;
    }
}
