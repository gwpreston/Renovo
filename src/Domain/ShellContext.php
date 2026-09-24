<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The little the shell knows beyond where you can go.
 *
 * Three reads, each through the scoping layer, and nothing else: the frame
 * holds no figures of its own, so a page's numbers are the page's to get
 * right. `ShellService` assembles this once per page.
 */
final class ShellContext
{
    /**
     * @param HouseholdLabel|null $household         Null for an account with no household.
     * @param int|null            $subscriptionCount The Subscriptions badge; null when not shown.
     * @param bool                $somethingDueSoon  Whether the bell carries its dot.
     */
    public function __construct(
        public readonly ?HouseholdLabel $household,
        public readonly ?int $subscriptionCount,
        public readonly RatesChip $rates,
        public readonly bool $somethingDueSoon,
    ) {
    }
}
