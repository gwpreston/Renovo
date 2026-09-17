<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Distribution;

/**
 * Where the recurring spend goes, and what the shares are shares *of*.
 *
 * `Support\Distribution` already holds the mechanical half of this job —
 * ordering the rows, keeping the largest few, naming the tail, working out each
 * share of a total. What it deliberately does not decide is the denominator,
 * because the denominator is the claim the picture makes.
 *
 * This is that decision, and it is now made on two screens: the my-subscriptions
 * widget draws it as proportion bars and the analytics screen draws it as a
 * donut. They are two pictures of one breakdown, so there is one breakdown.
 *
 * **The denominator is stated, and it degrades honestly.** When every currency
 * in play converts, there is one group and the total is the combined monthly
 * figure in the base currency — which is what lets two categories be compared
 * at all. When one currency has no rate, there is a group per currency, each
 * share against that currency's own monthly total. Unconvertible currencies are
 * separated, never blended: a bar or a segment drawn across currencies with no
 * rate between them would be a comparison nobody can make.
 *
 * @phpstan-import-type Bars from Distribution
 * @phpstan-type Group array{
 *     rows: list<array{name: string, amount_minor: int, percent: int}>,
 *     other: array{count: int, amount_minor: int, percent: int}|null,
 *     currency: string,
 *     total_minor: int
 * }
 * @phpstan-type Breakdown array{is_combined: bool, unconvertible: list<string>, groups: list<Group>}
 */
final class CategoryBreakdownService
{
    /** Distribution slices past this many are a legend, not a picture. */
    public const BARS = 6;

    public function __construct(
        private readonly ExchangeRateService $rates,
    ) {
    }

    /**
     * The breakdown, from the statistics the page has already loaded.
     *
     * Takes the `StatsService::dashboard()` array rather than a scope: the
     * screens that draw this have the statistics in hand, and walking the
     * household a second time to arrive at figures already on the page would be
     * two answers to one question waiting to differ.
     *
     * @param array<string, mixed> $stats
     * @return Breakdown
     */
    public function fromStats(array $stats): array
    {
        /** @var array{currency: string, amount_minor: int|null, unconvertible: list<string>} $combined */
        $combined = $stats['combined_monthly'];
        /** @var list<array{name: string, currency: string, monthly_minor: int, count: int}> $byCategory */
        $byCategory = $stats['by_category'];
        /** @var list<array{currency: string, monthly_minor: int, yearly_minor: int, count: int}> $recurring */
        $recurring = $stats['recurring'];

        if ($combined['amount_minor'] !== null) {
            return [
                'is_combined' => true,
                'unconvertible' => [],
                'groups' => $this->combinedGroup($byCategory, $combined['currency'], $combined['amount_minor']),
            ];
        }

        return [
            'is_combined' => false,
            'unconvertible' => $combined['unconvertible'],
            'groups' => $this->perCurrencyGroups($byCategory, $recurring),
        ];
    }

    /**
     * One group: every category converted into the base currency.
     *
     * @param list<array{name: string, currency: string, monthly_minor: int, count: int}> $byCategory
     * @return list<Group>
     */
    private function combinedGroup(array $byCategory, string $currency, int $total): array
    {
        if ($total <= 0) {
            return [];
        }

        $byName = [];
        foreach ($byCategory as $row) {
            $byName[$row['name']][$row['currency']] = ($byName[$row['name']][$row['currency']] ?? 0)
                + $row['monthly_minor'];
        }

        $rows = [];
        foreach ($byName as $name => $amounts) {
            $rows[] = [
                'name' => (string) $name,
                'amount_minor' => $this->rates->combine($amounts, $currency) ?? 0,
            ];
        }

        /** @var Group $group */
        $group = Distribution::bars($rows, $total, self::BARS) + [
            'currency' => $currency,
            'total_minor' => $total,
        ];

        return [$group];
    }

    /**
     * A group per currency, each against its own monthly total.
     *
     * The denominators are the per-currency subtotals the spend figures show,
     * so a share here and a total up there are two readings of one figure.
     *
     * @param list<array{name: string, currency: string, monthly_minor: int, count: int}> $byCategory
     * @param list<array{currency: string, monthly_minor: int, yearly_minor: int, count: int}> $recurring
     * @return list<Group>
     */
    private function perCurrencyGroups(array $byCategory, array $recurring): array
    {
        $rowsByCurrency = [];
        foreach ($byCategory as $row) {
            $rowsByCurrency[$row['currency']][] = [
                'name' => $row['name'],
                'amount_minor' => $row['monthly_minor'],
            ];
        }

        $groups = [];
        foreach ($recurring as $subtotal) {
            $currency = $subtotal['currency'];
            $rows = $rowsByCurrency[$currency] ?? [];

            if ($rows === [] || $subtotal['monthly_minor'] <= 0) {
                continue;
            }

            /** @var Group $group */
            $group = Distribution::bars($rows, $subtotal['monthly_minor'], self::BARS) + [
                'currency' => $currency,
                'total_minor' => $subtotal['monthly_minor'],
            ];

            $groups[] = $group;
        }

        return $groups;
    }
}
