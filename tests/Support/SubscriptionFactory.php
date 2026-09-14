<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\BillingCycle;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\Tag;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\SplitMode;
use App\Domain\SubscriptionType;
use DateTimeImmutable;

/**
 * Builds Subscription entities for tests that need one without a database.
 *
 * It exists so that adding a field to the entity is a change in one place
 * rather than in every test that happened to construct one. Each test names
 * only the handful of fields it actually cares about; everything else takes a
 * deliberately boring default.
 */
final class SubscriptionFactory
{
    /**
     * @param list<Tag> $tags
     */
    public static function make(
        int $id = 1,
        string $name = 'Example',
        int $priceMinor = 1000,
        string $currency = 'GBP',
        SubscriptionType $type = SubscriptionType::Recurring,
        ?BillingCycle $cycle = BillingCycle::Monthly,
        ?int $cycleDays = null,
        ?DateTimeImmutable $nextPaymentDate = null,
        ?DateTimeImmutable $startDate = null,
        ?int $anchorDay = null,
        ?NoticePeriod $noticePeriod = null,
        bool $isTrial = false,
        ?DateTimeImmutable $trialEndDate = null,
        ?int $convertsToPriceMinor = null,
        ?BillingCycle $convertsToBillingCycle = null,
        ?int $convertsToCycleDays = null,
        SplitMode $splitMode = SplitMode::None,
        int $usageCount = 0,
        ?int $usageRating = null,
        ?DateTimeImmutable $usageCountedSince = null,
        bool $isActive = true,
        ?int $categoryId = null,
        ?string $categoryName = null,
        int $ownerUserId = 1,
        ?int $payerUserId = null,
        int $householdId = 1,
        array $tags = [],
    ): Subscription {
        return new Subscription(
            id: $id,
            householdId: $householdId,
            ownerUserId: $ownerUserId,
            payerUserId: $payerUserId,
            name: $name,
            notes: null,
            price: Money::of($priceMinor, $currency),
            type: $type,
            billingCycle: $type->hasBillingCycle() ? $cycle : null,
            cycleDays: $cycleDays,
            nextPaymentDate: $nextPaymentDate ?? new DateTimeImmutable('2026-06-01'),
            startDate: $startDate,
            anchorDay: $anchorDay,
            noticePeriod: $noticePeriod ?? NoticePeriod::none(),
            isTrial: $isTrial,
            trialEndDate: $trialEndDate,
            convertsToPrice: $convertsToPriceMinor === null
                ? null
                : Money::of($convertsToPriceMinor, $currency),
            convertsToBillingCycle: $convertsToBillingCycle,
            convertsToCycleDays: $convertsToCycleDays,
            splitMode: $splitMode,
            usageCount: $usageCount,
            usageRating: $usageRating,
            usageCountedSince: $usageCountedSince,
            isActive: $isActive,
            logoPath: null,
            categoryId: $categoryId,
            categoryName: $categoryName,
            ownerName: null,
            payerName: null,
            tags: $tags,
            createdAt: new DateTimeImmutable('2026-01-01'),
            updatedAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
