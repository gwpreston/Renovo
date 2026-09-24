<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Rounding;

/**
 * A part of a whole as a CSS percentage, to a tenth.
 *
 * The dashboard's bars, meters and markers are sized on the server from minor
 * units, and this is the one place that turns two integers into a width. It is
 * per-mille arithmetic written with one decimal place, so no height or width on
 * the page is a float derived from an amount of money. Clamped to 0–100%: a
 * figure past its scale fills the scale rather than overflowing its card.
 */
final class CssPercent
{
    public static function of(int $part, int $whole): string
    {
        if ($whole <= 0 || $part <= 0) {
            return '0%';
        }

        $perMille = min(1000, Rounding::multiplyDivide($part, 1000, $whole));

        return intdiv($perMille, 10) . '.' . ($perMille % 10) . '%';
    }
}
