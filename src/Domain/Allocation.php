<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Divide an amount of money between weighted parties so that the parts add up
 * to exactly the whole.
 *
 * The naive approach — round each share independently — is wrong in a way that
 * is easy to miss and impossible to defend. £10.00 split three ways gives
 * £3.33 each and loses a penny; £0.10 split three ways gives 3p, 3p, 3p and
 * loses a penny that is a tenth of the bill. Money that vanishes between the
 * shares and the total is a bug, not a rounding preference.
 *
 * So the largest-remainder method is used instead: give everyone their exact
 * integer quotient, then hand the leftover minor units out one at a time,
 * largest fractional remainder first. The result always sums to the input, and
 * the extra penny goes to whoever was closest to deserving it.
 *
 * Ties are broken by position, which makes the allocation deterministic: the
 * same subscription with the same weights produces the same shares on every
 * page load, rather than the penny wandering between members.
 */
final class Allocation
{
    private function __construct()
    {
    }

    /**
     * @param list<int> $weights Non-negative; at least one must be positive.
     * @return list<int> One share per weight, in the same order, summing to
     *                   exactly $amountMinor.
     * @throws InvalidArgumentException when no weight is positive.
     */
    public static function byWeight(int $amountMinor, array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Cannot allocate between nobody.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('A share weight cannot be negative.');
            }
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            throw new InvalidArgumentException('At least one share must be greater than zero.');
        }

        // Negative amounts (a refund, a credit) allocate by the same rule with
        // the sign carried through, so the parts of a credit also sum exactly.
        $negative = $amountMinor < 0;
        $absolute = abs($amountMinor);

        $shares = [];
        $remainders = [];
        $distributed = 0;

        foreach ($weights as $index => $weight) {
            $exact = $absolute * $weight;
            $share = intdiv($exact, $totalWeight);
            $shares[$index] = $share;
            $remainders[$index] = $exact % $totalWeight;
            $distributed += $share;
        }

        $leftover = $absolute - $distributed;

        // Order by remainder descending, then by position, so the outcome does
        // not depend on PHP's sort stability or on hash order.
        $order = array_keys($remainders);
        usort($order, static function (int $a, int $b) use ($remainders): int {
            return $remainders[$b] <=> $remainders[$a] ?: $a <=> $b;
        });

        foreach ($order as $index) {
            if ($leftover <= 0) {
                break;
            }
            $shares[$index]++;
            $leftover--;
        }

        ksort($shares);

        return array_values(array_map(
            static fn (int $share): int => $negative ? -$share : $share,
            $shares,
        ));
    }

    /**
     * Split evenly between a number of parties.
     *
     * @return list<int>
     */
    public static function evenly(int $amountMinor, int $parties): array
    {
        if ($parties < 1) {
            throw new InvalidArgumentException('Cannot allocate between fewer than one party.');
        }

        return self::byWeight($amountMinor, array_fill(0, $parties, 1));
    }
}
