<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The navigation a single page renders, in the shapes the shell needs.
 *
 * All of them are projections of one catalogue, filtered by what this user is
 * allowed to do. `primary` and `tools` are the rail's two groups, and `account`
 * is the user card beneath them — a destination like any other, which lights
 * when you are on your own profile. `tabs` and `drawer` are the same
 * destinations split for a screen too narrow for a rail; the drawer's sheet
 * draws the user card at its top, so nothing is in the rail and missing from
 * the phone: `tabs` plus `drawer` plus `account` is `primary` plus `tools` plus
 * `account`, and a test says so.
 */
final class Navigation
{
    /**
     * @param list<NavLink> $primary The rail's main group.
     * @param list<NavLink> $tools   The rail's "Household tools" group.
     * @param NavLink       $account The user card: your own profile.
     * @param list<NavLink> $tabs    The narrow screen's bottom tab bar.
     * @param list<NavLink> $drawer  Everything else, behind the "More" sheet.
     */
    public function __construct(
        public readonly array $primary,
        public readonly array $tools,
        public readonly NavLink $account,
        public readonly array $tabs,
        public readonly array $drawer,
    ) {
    }

    /**
     * Whether the page you are on is one of the ones the More sheet holds.
     *
     * The disclosure that opens it carries the active marker when it is, so a
     * narrow screen still says where you are without being opened. The user
     * card counts: it is at the top of the sheet.
     */
    public function drawerHoldsActive(): bool
    {
        foreach ([...$this->drawer, $this->account] as $link) {
            if ($link->active) {
                return true;
            }
        }

        return false;
    }
}
