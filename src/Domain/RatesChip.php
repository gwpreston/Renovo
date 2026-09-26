<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * The top bar's rates chip: the base currency, when the rates were last
 * fetched, and whether that is recent enough.
 *
 * Built from what the cache already records. Rendering it never fetches — a
 * page view that could reach out to a rate provider would make every screen as
 * slow as the provider, and an instance with no route out would stall on each
 * one.
 */
final class RatesChip
{
    /**
     * @param bool $linksToSettings Whether the reader may open the settings
     *                              that choose the provider; for anyone else
     *                              the chip is a status, not a link.
     */
    public function __construct(
        public readonly string $currency,
        public readonly RatesState $state,
        public readonly ?DateTimeImmutable $refreshedAt,
        public readonly bool $linksToSettings,
    ) {
    }
}
