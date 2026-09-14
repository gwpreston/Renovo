<?php

declare(strict_types=1);

namespace App\Domain;

use ArithmeticError;
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
     * Multiply then divide in one step, rounding half away from zero.
     *
     * The naive `divide($value * $multiplier, $divisor)` is wrong for large
     * operands, and wrong silently: PHP does not raise on integer overflow, it
     * converts the product to a float. A currency amount that quietly becomes
     * a float is precisely the failure this codebase forbids, and it would
     * surface as a rounding discrepancy months later rather than as an error.
     *
     * So the product is never formed when it does not have to be. The value is
     * divided into a whole part and a remainder first — the identity
     *
     *     v·n/d  =  (v div d)·n  +  ((v mod d)·n)/d
     *
     * keeps every intermediate at most `$divisor` times smaller than the naive
     * one, which is what makes a rate scaled by 10^8 usable on realistic
     * amounts. Anything that still cannot be represented throws rather than
     * returning a number that looks plausible — and the overflow is detected
     * before the multiplication, because afterwards the damage is done.
     *
     * @throws ArithmeticError when the exact result cannot be held in an int.
     */
    public static function multiplyDivide(int $value, int $multiplier, int $divisor): int
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        if ($value === PHP_INT_MIN || $multiplier === PHP_INT_MIN || $divisor === PHP_INT_MIN) {
            throw new ArithmeticError('Operand too large to negate safely.');
        }

        $negative = ($value < 0) !== (($multiplier < 0) !== ($divisor < 0));

        $value = abs($value);
        $multiplier = abs($multiplier);
        $divisor = abs($divisor);

        // Cancelling common factors first keeps the intermediates small. For
        // the common case — a rate scaled by 10^8 applied between two
        // two-decimal currencies — this removes the scaling entirely.
        $common = self::greatestCommonDivisor($multiplier, $divisor);
        if ($common > 1) {
            $multiplier = intdiv($multiplier, $common);
            $divisor = intdiv($divisor, $common);
        }

        $whole = self::multiplyExact(intdiv($value, $divisor), $multiplier);
        $partial = self::multiplyExact($value % $divisor, $multiplier);

        $quotient = intdiv($partial, $divisor);
        $remainder = $partial % $divisor;

        // Half away from zero, written so that neither side can overflow the
        // way `2 * $remainder >= $divisor` would for a large divisor.
        if ($remainder >= $divisor - $remainder) {
            $quotient++;
        }

        $result = self::addExact($whole, $quotient);

        return $negative ? -$result : $result;
    }

    /**
     * Multiply, refusing rather than overflowing.
     *
     * The check happens *before* the multiplication, not after. Inspecting the
     * product would be too late: PHP has already converted it to a float by
     * then, and the value has already lost precision. Both operands are
     * non-negative — the callers take absolute values first — which is what
     * makes the single comparison sufficient.
     *
     * @param int<0, max> $a
     * @param int<0, max> $b
     * @throws ArithmeticError
     */
    private static function multiplyExact(int $a, int $b): int
    {
        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) {
            throw new ArithmeticError(sprintf('Integer overflow multiplying %d by %d.', $a, $b));
        }

        return $a * $b;
    }

    /**
     * @param int<0, max> $a
     * @param int<0, max> $b
     * @throws ArithmeticError
     */
    private static function addExact(int $a, int $b): int
    {
        if ($b > PHP_INT_MAX - $a) {
            throw new ArithmeticError(sprintf('Integer overflow adding %d to %d.', $a, $b));
        }

        return $a + $b;
    }

    /**
     * @param int<0, max> $a
     * @param int<0, max> $b
     */
    private static function greatestCommonDivisor(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
