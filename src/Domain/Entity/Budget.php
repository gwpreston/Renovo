<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\BudgetPeriod;
use App\Domain\Money;

/**
 * A spending limit for one member, over one window, optionally narrowed to a
 * single category.
 *
 * The budget holds only the limit. What has been projected against it is
 * computed from the forecast, never stored, so a budget cannot go stale.
 */
final class Budget
{
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly int $ownerUserId,
        public readonly string $name,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly BudgetPeriod $period,
        public readonly Money $amount,
        public readonly ?int $warnThresholdPercent,
        public readonly bool $isActive,
        public readonly ?string $ownerName,
    ) {
    }

    public function isOverall(): bool
    {
        return $this->categoryId === null;
    }
}
