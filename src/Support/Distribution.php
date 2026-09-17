<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Rounding;

/**
 * Amounts turned into the bars that show how a total is divided up.
 *
 * Only the mechanical half of that job lives here: ordering the rows, keeping
 * the largest few, naming the tail rather than dropping it, and working out
 * each share of a total the caller supplies. What the total *is* — a combined
 * figure in the base currency, or one currency's own subtotal — is the caller's
 * decision, because it is the claim the bars make and it differs between the
 * screens that draw them.
 *
 * The percentages are integer arithmetic on minor units, like every other
 * figure in the application: a share is computed from the amounts, never from a
 * float ratio of them.
 *
 * @phpstan-type Bar array{name: string, amount_minor: int, percent: int}
 * @phpstan-type Tail array{count: int, amount_minor: int, percent: int}
 * @phpstan-type Bars array{rows: list<Bar>, other: Tail|null}
 */
final class Distribution
{
    private function __construct()
    {
    }

    /**
     * The largest `$limit` rows as bars, with everything else named as one.
     *
     * A total of zero or less produces no bars at all rather than a row of
     * empty ones: there is no share of nothing, and a bar drawn against an
     * absent denominator would be a picture of a number nobody computed.
     *
     * @param list<array{name: string, amount_minor: int}> $rows
     * @return Bars
     */
    public static function bars(array $rows, int $totalMinor, int $limit): array
    {
        if ($totalMinor <= 0) {
            return ['rows' => [], 'other' => null];
        }

        // Largest first, and alphabetically within a tie so that two categories
        // costing the same do not swap places between page loads.
        usort($rows, static fn (array $a, array $b): int => $b['amount_minor'] <=> $a['amount_minor']
            ?: strcmp($a['name'], $b['name']));

        $shown = array_slice($rows, 0, $limit);
        $rest = array_slice($rows, $limit);

        $bars = array_map(
            static fn (array $row): array => [
                'name' => $row['name'],
                'amount_minor' => $row['amount_minor'],
                'percent' => Rounding::multiplyDivide($row['amount_minor'], 100, $totalMinor),
            ],
            $shown,
        );

        if ($rest === []) {
            return ['rows' => $bars, 'other' => null];
        }

        // Named rather than dropped: a picture that quietly left out the tail
        // would misstate every share drawn beside it.
        $tailMinor = (int) array_sum(array_column($rest, 'amount_minor'));

        return [
            'rows' => $bars,
            'other' => [
                'count' => count($rest),
                'amount_minor' => $tailMinor,
                'percent' => Rounding::multiplyDivide($tailMinor, 100, $totalMinor),
            ],
        ];
    }
}
