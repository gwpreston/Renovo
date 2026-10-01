<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One "change price" in a scenario: the new price, in the subscription's own
 * currency, and the cycle it is billed on — null to keep the one it has.
 */
final class ScenarioChange
{
    public function __construct(
        public readonly Money $price,
        public readonly ?BillingCycle $cycle = null,
        public readonly ?int $cycleDays = null,
    ) {
    }
}
