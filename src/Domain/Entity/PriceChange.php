<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Money;
use App\Domain\PriceChangeSource;
use DateTimeImmutable;

/**
 * One entry in a subscription's price history.
 *
 * Immutable here, and immutable in the schema once it has taken effect: a
 * correction to a price is another row, which is what makes the history a
 * history. The one exception is a row that has not happened yet — an
 * announced price is a plan, not a fact, so the end of an offer can be moved
 * — and the promotional flag, which labels a price rather than being part of
 * it. See `PriceHistoryRepository`.
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
        // An introductory or promotional price. It describes the price, not
        // why the row was written, which is what `source` says; the offer
        // ends where the next row takes effect.
        public readonly bool $isPromotional = false,
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

    /**
     * True when this row is a provider putting the price up from `$previous`.
     *
     * One definition, read by the insight rules, the analytics screen's
     * price-rise count and its household price history, so that the three
     * cannot disagree about what counts. A higher price in the same currency,
     * and not a trial converting: a trial ending reads "£0, then £12.99",
     * which is a rise from nothing rather than news. A currency change is a
     * re-denomination, which `differenceFrom()` already refuses to subtract.
     */
    public function isRiseFrom(?self $previous): bool
    {
        if ($this->source === PriceChangeSource::TrialConversion || $this->isConversionFrom($previous)) {
            return false;
        }

        $step = $this->differenceFrom($previous);

        return $step !== null && $step > 0;
    }

    /**
     * True when this row re-denominates the price rather than changing it:
     * recorded as a currency change, or simply in a different currency from
     * the row before.
     */
    public function isConversionFrom(?self $previous): bool
    {
        return $this->source === PriceChangeSource::CurrencyChange
            || ($previous !== null && $previous->price->currency !== $this->price->currency);
    }

    /**
     * True when this row is the end of an offer: the price before it was
     * promotional, and this one is what follows.
     *
     * One definition, read by every surface that would otherwise call the
     * step a price rise — the trend, the forecast, the calendar, the insight
     * rules and the price-change alert — so that none of them can call the
     * same step two different things. Wording only: it changes nothing about
     * which price is current or what anything costs.
     */
    public function endsOfferFrom(?self $previous): bool
    {
        // A second intro price straight after the first is the offer
        // continuing at another rate, not ending.
        return $previous !== null
            && $previous->isPromotional
            && !$this->isPromotional
            && !$this->isConversionFrom($previous);
    }
}
