<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The one word the interface uses for where a subscription stands.
 *
 * Derived, never stored — see `Subscription::status()` for the order. Paused
 * and Cancelled differ in intent rather than in effect on the figures: both are
 * out of every total, but a paused row may resume and a cancelled one is
 * finished, and undoing a cancel lands on Paused so that it cannot silently
 * start charging again.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trial = 'trial';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return 'state.' . $this->value;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
