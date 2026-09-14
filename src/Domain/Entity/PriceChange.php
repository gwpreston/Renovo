<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Money;
use App\Domain\PriceChangeSource;
use DateTimeImmutable;

/**
 * One entry in a subscription's price history.
 *
 * Immutable in the schema and immutable here: there is no setter, and nothing
 * in the application updates one of these rows. A correction to a price is
 * another row, which is what makes the history a history.
 */
final class PriceChange
{
    public function __construct(
        public readonly int $id,
        public readonly int $subscriptionId,
        public readonly Money $price,
        public readonly DateTimeImmutable $effectiveFrom,
        public readonly PriceChangeSource $source,
        public readonly ?string $note,
        public readonly ?string $createdByName,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * True when this change has already taken effect as at the given date.
     */
    public function hasTakenEffectOn(DateTimeImmutable $date): bool
    {
        return $this->effectiveFrom->setTime(0, 0) <= $date->setTime(0, 0);
    }

    public function isScheduled(DateTimeImmutable $today): bool
    {
        return !$this->hasTakenEffectOn($today);
    }

    /**
     * The change from a previous price, in minor units, or null when there is
     * nothing to compare against.
     */
    public function differenceFrom(?self $previous): ?int
    {
        if ($previous === null || $previous->price->currency !== $this->price->currency) {
            // A currency change is a re-denomination, not a price rise, and
            // subtracting across currencies would produce a meaningless number.
            return null;
        }

        return $this->price->amountMinor - $previous->price->amountMinor;
    }
}
