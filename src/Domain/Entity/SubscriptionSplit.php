<?php

declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * One member's stake in a shared subscription.
 *
 * The weight is stored; the money is not. A share recorded as an amount stops
 * being right the moment the price changes, and a subscription whose price went
 * up last month would still be dividing last month's cost.
 */
final class SubscriptionSplit
{
    public function __construct(
        public readonly int $id,
        public readonly int $subscriptionId,
        public readonly int $userId,
        public readonly int $shareUnits,
        public readonly ?string $userName,
    ) {
    }
}
