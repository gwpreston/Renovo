<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What the top bar's rates chip says about the exchange-rate cache.
 *
 * Three states because there are three things a reader should act on
 * differently: the conversions are current; they are from an older fetch and
 * still used; there is nothing to convert with at all. The last two wear a
 * warning, and each says so in words as well as colour.
 */
enum RatesState: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Unavailable = 'unavailable';

    public function isWarning(): bool
    {
        return $this !== self::Fresh;
    }
}
