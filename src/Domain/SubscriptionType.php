<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Whether a subscription actually recurs.
 *
 * One-off and lifetime entries are tracked so the user has a complete picture
 * of what they pay for, but they are deliberately excluded from recurring
 * monthly and yearly totals — amortising a lifetime licence across an
 * arbitrary horizon would make the headline figures meaningless.
 */
enum SubscriptionType: string
{
    case Recurring = 'recurring';
    case OneOff = 'one_off';
    case Lifetime = 'lifetime';

    public function countsTowardsRecurringTotals(): bool
    {
        return $this === self::Recurring;
    }

    public function hasBillingCycle(): bool
    {
        return $this === self::Recurring;
    }

    public function labelKey(): string
    {
        return 'type.' . $this->value;
    }
}
