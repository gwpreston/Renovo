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
 * **It breaks down more than categories.** Payment methods have the same shape
 * and the same currency problem, so the statistics carry `by_payment_method`
 * beside `by_category` and this takes the key to read. A row may carry a
 * `colour` — payment methods store one for their segment — which rides through
 * to the group untouched; a row without one is coloured by the palette.
 *
 * @phpstan-import-type Bars from Distribution
 * @phpstan-type Source list<array{name: string, currency: string, monthly_minor: int, colour?: string|null}>
 * @phpstan-type Group array{
 *     rows: list<array{name: string, amount_minor: int, percent: int, colour: string|null}>,
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

    public const BY_CATEGORY = 'by_category';
    public const BY_PAYMENT_METHOD = 'by_payment_method';

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
     * @param self::BY_* $key Which grouping of the statistics to break down.
     * @return Breakdown
     */
    public function fromStats(array $stats, string $key = self::BY_CATEGORY): array
    {
        /** @var array{currency: string, amount_minor: int|null, unconvertible: list<string>} $combined */
        $combined = $stats['combined_monthly'];
        /** @var Source $byCategory */
        $byCategory = $stats[$key];
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
     * @param Source $byCategory
     * @return list<Group>
     */
    private function combinedGroup(array $byCategory, string $currency, int $total): array
    {
        if ($total <= 0) {
            return [];
        }

        $byName = [];
        $colours = [];
        foreach ($byCategory as $row) {
            $byName[$row['name']][$row['currency']] = ($byName[$row['name']][$row['currency']] ?? 0)
                + $row['monthly_minor'];
            $colours[$row['name']] ??= $row['colour'] ?? null;
        }

        $rows = [];
        foreach ($byName as $name => $amounts) {
            $rows[] = [
                'name' => (string) $name,
                'amount_minor' => $this->rates->combine($amounts, $currency) ?? 0,
            ];
        }

        /** @var Group $group */
        $group = $this->withColours(Distribution::bars($rows, $total, self::BARS), $colours) + [
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
     * @param Source $byCategory
     * @param list<array{currency: string, monthly_minor: int, yearly_minor: int, count: int}> $recurring
     * @return list<Group>
     */
    private function perCurrencyGroups(array $byCategory, array $recurring): array
    {
        $rowsByCurrency = [];
        $colours = [];
        foreach ($byCategory as $row) {
            $colours[$row['name']] ??= $row['colour'] ?? null;
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
            $group = $this->withColours(Distribution::bars($rows, $subtotal['monthly_minor'], self::BARS), $colours) + [
                'currency' => $currency,
                'total_minor' => $subtotal['monthly_minor'],
            ];

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Put each row's colour back after `Distribution` has ordered and cut the
     * rows — it keeps only what it needs to do that, by design.
     *
     * @param Bars $bars
     * @param array<string, string|null> $colours
     * @return array{rows: list<array{name: string, amount_minor: int, percent: int, colour: string|null}>,
     *     other: array{count: int, amount_minor: int, percent: int}|null}
     */
    private function withColours(array $bars, array $colours): array
    {
        $bars['rows'] = array_map(
            static fn (array $row): array => $row + ['colour' => $colours[$row['name']] ?? null],
            $bars['rows'],
        );

        return $bars;
    }
}
