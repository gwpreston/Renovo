<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\BillingCycle;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\Rounding;
use App\Domain\SplitMode;
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
        public readonly bool $isTrial,
        public readonly ?DateTimeImmutable $trialEndDate,
        public readonly ?Money $convertsToPrice,
        public readonly ?BillingCycle $convertsToBillingCycle,
        public readonly ?int $convertsToCycleDays,
        public readonly SplitMode $splitMode,
        public readonly int $usageCount,
        public readonly ?int $usageRating,
        public readonly ?DateTimeImmutable $usageCountedSince,
        public readonly bool $isActive,
        public readonly ?string $reminderDays,
        public readonly ?string $logoPath,
        public readonly ?string $websiteUrl,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly ?string $ownerName,
        public readonly ?string $payerName,
        public readonly array $tags,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        /**
         * Whether the owner has a picture, so the list can draw a face without
         * a query per row. Trailing with a default because it is an addition
         * to an entity that predates it, like the display preferences on User.
         */
        public readonly bool $ownerHasAvatar = false,
        /**
         * What it is paid with, joined for display as the category is. The
         * icon and logo are the method's, carried here so the list can draw
         * a badge without a query per row.
         */
        public readonly ?int $paymentMethodId = null,
        public readonly ?string $paymentMethodName = null,
        public readonly ?string $paymentMethodIcon = null,
        public readonly ?string $paymentMethodLogoPath = null,
        public readonly ?string $paymentMethodColour = null,
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
     * The next date money actually changes hands.
     *
     * For a running trial that is the conversion date, not `nextPaymentDate` —
     * a trial has no payment date yet, and whatever the column holds is either
     * null or a leftover default. Anything reasoning about the next charge has
     * to ask this rather than reading the column, or it will quietly answer for
     * a payment that is not going to happen.
     */
    public function nextChargeDate(): ?DateTimeImmutable
    {
        if ($this->isTrial && $this->trialEndDate !== null) {
            return $this->trialEndDate;
        }

        return $this->nextPaymentDate;
    }

    /**
     * The last day on which the subscription can be cancelled and still avoid
     * the next charge.
     *
     * Measured from the next *charge*, which for a trial is its conversion.
     * Getting that wrong would tell somebody on a 30-day notice that their
     * deadline had already passed when in fact they had a fortnight — the
     * single most damaging thing this page could do.
     */
    public function cancellationDeadline(): ?DateTimeImmutable
    {
        $charge = $this->nextChargeDate();
        if ($charge === null) {
            return null;
        }

        return $this->noticePeriod->deadlineBefore($charge);
    }

    /**
     * This subscription's own reminder lead times, or null to use the user's.
     *
     * An empty string is not the same as null and the difference is the point:
     * null means "whatever my preference says", an empty list means "never
     * remind me about this one". A single expensive annual policy and a £2
     * monthly app want different answers, and neither wants the other's.
     *
     * @return list<int>|null
     */
    public function reminderDaysList(): ?array
    {
        if ($this->reminderDays === null) {
            return null;
        }

        $days = [];
        foreach (explode(',', $this->reminderDays) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $days[] = (int) $part;
            }
        }

        rsort($days);

        return array_values(array_unique($days));
    }

    public function daysUntilNextPayment(DateTimeImmutable $today): ?int
    {
        return $this->daysUntil($this->nextPaymentDate, $today);
    }

    /**
     * True while the trial is still running.
     *
     * A trial whose end date has passed is not "a trial that ended" — it is a
     * paid subscription whose conversion has not been applied yet. The
     * catch-up turns one into the other.
     */
    public function isTrialActiveOn(DateTimeImmutable $date): bool
    {
        return $this->isTrial
            && $this->trialEndDate !== null
            && $this->trialEndDate->setTime(0, 0) >= $date->setTime(0, 0);
    }

    public function trialHasEndedBy(DateTimeImmutable $date): bool
    {
        return $this->isTrial
            && $this->trialEndDate !== null
            && $this->trialEndDate->setTime(0, 0) < $date->setTime(0, 0);
    }

    public function daysUntilTrialEnds(DateTimeImmutable $today): ?int
    {
        return $this->daysUntil($this->trialEndDate, $today);
    }

    /**
     * What this subscription will cost once the trial converts — the
     * converts-to price when one was recorded, and otherwise the price it
     * already has.
     */
    public function priceAfterConversion(): Money
    {
        return $this->convertsToPrice ?? $this->price;
    }

    public function billingCycleAfterConversion(): ?BillingCycle
    {
        return $this->convertsToBillingCycle ?? $this->billingCycle;
    }

    public function cycleDaysAfterConversion(): ?int
    {
        return $this->convertsToBillingCycle !== null ? $this->convertsToCycleDays : $this->cycleDays;
    }

    /**
     * Cost per recorded use, in minor units, or null when there is nothing to
     * divide by.
     *
     * Measured against the period the count covers rather than against a single
     * bill: forty uses is excellent over a month and poor over three years, and
     * a figure that ignored the period would rate them the same.
     */
    public function costPerUseMinor(DateTimeImmutable $today): ?int
    {
        if ($this->usageCount <= 0) {
            return null;
        }

        $monthly = $this->monthlyMinor();
        if ($monthly === null) {
            return null;
        }

        $months = max(1, $this->usageMonths($today));

        return Rounding::divide($monthly * $months, $this->usageCount);
    }

    /**
     * Whole months the usage count covers, at least one.
     */
    public function usageMonths(DateTimeImmutable $today): int
    {
        $since = $this->usageCountedSince ?? $this->startDate ?? $this->createdAt;

        $months = (int) $since->setTime(0, 0)->diff($today->setTime(0, 0))->format('%r%a');

        return max(1, (int) round($months / 30.44));
    }

    private function daysUntil(?DateTimeImmutable $date, DateTimeImmutable $today): ?int
    {
        if ($date === null) {
            return null;
        }

        return (int) $today->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');
    }
}
