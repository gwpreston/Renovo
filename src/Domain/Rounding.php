<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Integer arithmetic helpers. Deliberately float-free: every currency
 * computation in the application routes through these.
 */
final class Rounding
{
    private function __construct()
    {
    }

    /**
     * Integer division rounding half away from zero.
     *
     * Used wherever a money amount has to be divided (annual to monthly, a
     * daily-rate normalisation) so that results are deterministic and never
     * drift the way repeated float division does.
     */
    public static function divide(int $dividend, int $divisor): int
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        if ($divisor < 0) {
            $dividend = -$dividend;
            $divisor = -$divisor;
        }

        $negative = $dividend < 0;
        $absolute = abs($dividend);

        $result = intdiv(2 * $absolute + $divisor, 2 * $divisor);

        return $negative ? -$result : $result;
    }

    /**
     * Multiply then divide in one step, rounding half away from zero, keeping
     * the intermediate product exact.
     */
    public static function multiplyDivide(int $value, int $multiplier, int $divisor): int
    {
        return self::divide($value * $multiplier, $divisor);
    }
}
