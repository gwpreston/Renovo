<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The navigation a single page renders, in the four shapes the shell needs.
 *
 * All four are projections of one catalogue, filtered by what this user is
 * allowed to do. `primary` and `tools` are the rail's two groups; `tabs` and
 * `drawer` are the same destinations split for a screen too narrow for a rail.
 * Nothing is in the rail and missing from the phone: `tabs` plus `drawer` is
 * `primary` plus `tools`, and a test says so.
 */
final class Navigation
{
    /**
     * @param list<NavLink> $primary The rail's main group.
     * @param list<NavLink> $tools   The rail's bottom group.
     * @param list<NavLink> $tabs    The narrow screen's bottom tab bar.
     * @param list<NavLink> $drawer  Everything else, behind the "More" disclosure.
     */
    public function __construct(
        public readonly array $primary,
        public readonly array $tools,
        public readonly array $tabs,
        public readonly array $drawer,
    ) {
    }

    /**
     * Whether the page you are on is one of the ones the drawer hides.
     *
     * The disclosure that opens the drawer carries the active marker when it
     * is, so a narrow screen still says where you are without being opened.
     */
    public function drawerHoldsActive(): bool
    {
        foreach ($this->drawer as $link) {
            if ($link->active) {
                return true;
            }
        }

        return false;
    }
}
