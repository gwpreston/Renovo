<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The window a budget measures.
 *
 * Both are *rolling* windows starting today rather than calendar periods, and
 * the choice is deliberate. A calendar month would have to include the charges
 * already taken earlier in the month, and this application keeps no payment
 * ledger — it knows what is due, not what has been paid. A budget that silently
 * omitted the first three weeks of the month would read as comfortably under
 * when it was not.
 *
 * So "monthly" means the next month from today, and "annual" the next twelve.
 * The figure is always complete, and it is stated that way in the UI.
 */
enum BudgetPeriod: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    /**
     * Length of the window, in months.
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Annual => 12,
        };
    }

    public function labelKey(): string
    {
        return 'budget_period.' . $this->value;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
