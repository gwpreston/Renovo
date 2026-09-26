<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\BudgetPeriod;
use App\Domain\Money;

/**
 * A spending limit over one window, optionally narrowed to a single category,
 * measuring one member's share or — with no subject — the whole household.
 *
 * The owner is whoever set it; the subject is whose spending it measures. For
 * every budget that predates the distinction the two are the same member.
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
        public readonly ?int $subjectUserId = null,
        public readonly ?string $subjectName = null,
    ) {
    }

    public function isOverall(): bool
    {
        return $this->categoryId === null;
    }

    /**
     * Whether it measures the whole household rather than one member's share.
     */
    public function isHousehold(): bool
    {
        return $this->subjectUserId === null;
    }
}
