<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Attachment;
use App\Domain\Entity\Category;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\Tag;
use DateTimeImmutable;

/**
 * Entities as the API represents them.
 *
 * One class, so that the backup exporter, the importer's round trip and the
 * endpoints all describe a subscription the same way. If the representation
 * lived in each controller, an export would drift from what the API serves and
 * "restore what you exported" would quietly stop being true.
 *
 * Money is always an integer in minor units beside its currency code, never a
 * decimal and never a float. A JSON number with a fractional part is a binary
 * float by the time most clients have parsed it, and 19.99 is not representable
 * in one — which is the whole reason this application stores integers.
 */
final class Resource
{
    /**
     * @return array<string, mixed>
     */
    public static function subscription(Subscription $subscription, DateTimeImmutable $today): array
    {
        return [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'notes' => $subscription->notes,
            'price_minor' => $subscription->price->amountMinor,
            'currency' => $subscription->price->currency,
            'subscription_type' => $subscription->type->value,
            'billing_cycle' => $subscription->billingCycle?->value,
            'cycle_days' => $subscription->cycleDays,
            'next_payment_date' => self::date($subscription->nextPaymentDate),
            'start_date' => self::date($subscription->startDate),
            'anchor_day' => $subscription->anchorDay,
            'notice_period_amount' => $subscription->noticePeriod->amount,
            'notice_period_unit' => $subscription->noticePeriod->isSet()
                ? $subscription->noticePeriod->unit
                : null,
            'reminder_days' => $subscription->reminderDaysList(),
            'is_trial' => $subscription->isTrial,
            'trial_end_date' => self::date($subscription->trialEndDate),
            'converts_to_price_minor' => $subscription->convertsToPrice?->amountMinor,
            'converts_to_billing_cycle' => $subscription->convertsToBillingCycle?->value,
            'converts_to_cycle_days' => $subscription->convertsToCycleDays,
            'is_active' => $subscription->isActive,
            'category_id' => $subscription->categoryId,
            'category_name' => $subscription->categoryName,
            'owner_user_id' => $subscription->ownerUserId,
            'payer_user_id' => $subscription->payerUserId,
            'tags' => array_map(static fn (Tag $tag): string => $tag->name, $subscription->tags),
            'split_mode' => $subscription->splitMode->value,
            'usage_count' => $subscription->usageCount,
            'usage_rating' => $subscription->usageRating,
            'logo_path' => $subscription->logoPath,
            'website_url' => $subscription->websiteUrl,
            // Derived, and read-only. A client that recomputed these would have
            // to reimplement the billing-cycle normalisation to get them right.
            'monthly_minor' => $subscription->monthlyMinor(),
            'yearly_minor' => $subscription->yearlyMinor(),
            'next_charge_date' => self::date($subscription->nextChargeDate()),
            'cancellation_deadline' => self::date($subscription->cancellationDeadline()),
            'days_until_next_payment' => $subscription->daysUntilNextPayment($today),
            'created_at' => self::timestamp($subscription->createdAt),
            'updated_at' => self::timestamp($subscription->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'colour' => $category->colour,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tag(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'subscription_id' => $attachment->subscriptionId,
            'filename' => $attachment->originalFilename,
            'mime_type' => $attachment->mimeType,
            'size_bytes' => $attachment->sizeBytes,
            'period_date' => self::date($attachment->periodDate),
            'created_at' => self::timestamp($attachment->createdAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function token(ApiToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'public_id' => $token->publicId,
            'abilities' => $token->abilities->value,
            'last_used_at' => self::timestamp($token->lastUsedAt),
            'expires_at' => self::timestamp($token->expiresAt),
            'revoked_at' => self::timestamp($token->revokedAt),
            'created_at' => self::timestamp($token->createdAt),
        ];
    }

    private static function date(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    private static function timestamp(?DateTimeImmutable $moment): ?string
    {
        return $moment?->format(DATE_ATOM);
    }
}
