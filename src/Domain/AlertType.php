<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The things this application will interrupt somebody about.
 *
 * The list is short on purpose. A notification that is not worth acting on
 * teaches the user to ignore the ones that are, so each of these is a moment
 * where money is about to move and there is still something to be done about
 * it — or, in the case of a passed deadline, where knowing late is still
 * better than not knowing.
 */
enum AlertType: string
{
    /** A payment is coming up. */
    case Renewal = 'renewal';

    /** A free trial is about to start charging. */
    case TrialConversion = 'trial_conversion';

    /** The last day to cancel and avoid the next charge. */
    case CancelBy = 'cancel_by';

    /** Projected spend has gone past a budget. */
    case BudgetExceeded = 'budget_exceeded';

    /**
     * Whether the alert is anchored to a future date, and therefore fires on
     * the user's lead times.
     *
     * A budget breach is the exception: it is a state the user is already in,
     * not a date approaching, so "thirty days before" means nothing for it.
     */
    public function usesLeadTimes(): bool
    {
        return $this !== self::BudgetExceeded;
    }

    public function label(): string
    {
        return match ($this) {
            self::Renewal => 'Upcoming renewal',
            self::TrialConversion => 'Trial about to convert',
            self::CancelBy => 'Cancellation deadline',
            self::BudgetExceeded => 'Budget projected to be exceeded',
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
