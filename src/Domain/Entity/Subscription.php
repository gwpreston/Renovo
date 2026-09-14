<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\BillingCycle;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\SubscriptionType;
use DateTimeImmutable;

/**
 * One tracked subscription or recurring bill.
 *
 * `ownerUserId` is the member the cost belongs to and the column the isolation
 * rules key on. `payerUserId` is who actually pays it, which may be somebody
 * else in the household and carries no visibility meaning.
 */
final class Subscription
{
    /**
     * @param list<Tag> $tags
     */
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly int $ownerUserId,
        public readonly ?int $payerUserId,
        public readonly string $name,
        public readonly ?string $notes,
        public readonly Money $price,
        public readonly SubscriptionType $type,
        public readonly ?BillingCycle $billingCycle,
        public readonly ?int $cycleDays,
        public readonly ?DateTimeImmutable $nextPaymentDate,
        public readonly ?DateTimeImmutable $startDate,
        public readonly ?int $anchorDay,
        public readonly NoticePeriod $noticePeriod,
        public readonly bool $isActive,
        public readonly ?string $logoPath,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly ?string $ownerName,
        public readonly ?string $payerName,
        public readonly array $tags,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Equivalent cost over one month, in minor units, or null when the entry
     * is not a recurring one and therefore has no meaningful monthly figure.
     */
    public function monthlyMinor(): ?int
    {
        if (!$this->type->countsTowardsRecurringTotals() || $this->billingCycle === null) {
            return null;
        }

        return $this->billingCycle->monthlyMinor($this->price->amountMinor, $this->cycleDays);
    }

    public function yearlyMinor(): ?int
    {
        if (!$this->type->countsTowardsRecurringTotals() || $this->billingCycle === null) {
            return null;
        }

        return $this->billingCycle->annualMinor($this->price->amountMinor, $this->cycleDays);
    }

    /**
     * The last day on which the subscription can be cancelled and still avoid
     * the next charge.
     */
    public function cancellationDeadline(): ?DateTimeImmutable
    {
        if ($this->nextPaymentDate === null) {
            return null;
        }

        return $this->noticePeriod->deadlineBefore($this->nextPaymentDate);
    }

    public function daysUntilNextPayment(DateTimeImmutable $today): ?int
    {
        if ($this->nextPaymentDate === null) {
            return null;
        }

        return (int) $today->setTime(0, 0)->diff($this->nextPaymentDate->setTime(0, 0))->format('%r%a');
    }
}
