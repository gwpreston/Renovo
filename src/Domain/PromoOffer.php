<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * What the form says about an introductory price: when it ends and what it
 * becomes.
 *
 * Not stored as such. The price itself is a promotional history row, and its
 * end is the scheduled row this describes — the offer ends where the next row
 * takes effect, so there is no second date to drift out of step. Both are null
 * when the end is not known yet, which is allowed: the row is still badged,
 * and an insight asks for the date.
 */
final class PromoOffer
{
    public function __construct(
        public readonly ?DateTimeImmutable $endsOn,
        public readonly ?Money $then,
    ) {
    }

    public function hasEnd(): bool
    {
        return $this->endsOn !== null && $this->then !== null;
    }
}
