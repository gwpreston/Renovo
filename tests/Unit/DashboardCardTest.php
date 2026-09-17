<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\DashboardCard;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's cards, as a contract rather than as a convention.
 *
 * A card is an enum case, a template and a label. Adding one and forgetting
 * either of the other two fails here rather than on the page — the template
 * with a fatal Twig error, the label with a raw translation key printed in the
 * settings form where its name should be.
 */
final class DashboardCardTest extends TestCase
{
    public function testEveryCardHasATemplate(): void
    {
        foreach (DashboardCard::cases() as $card) {
            $path = dirname(__DIR__, 2) . '/templates/dashboard/cards/' . $card->value . '.twig';

            self::assertFileExists($path, $card->value . ' has no template');
        }
    }

    public function testEveryCardHasALabel(): void
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require dirname(__DIR__, 2) . '/translations/en.php';

        foreach (DashboardCard::cases() as $card) {
            self::assertArrayHasKey($card->labelKey(), $catalogue, $card->value . ' has no label');
        }
    }

    /**
     * The grid is three columns. A card asking for four would silently become
     * one row of its own with a hole beside it.
     */
    public function testEverySpanFitsTheGrid(): void
    {
        foreach (DashboardCard::cases() as $card) {
            self::assertGreaterThanOrEqual(1, $card->columnSpan(), $card->value);
            self::assertLessThanOrEqual(3, $card->columnSpan(), $card->value);
        }
    }

    /**
     * The design's split middle section: the chart and the usage widget are a
     * row between them, which is the whole reason a card declares a span.
     */
    public function testTheChartAndTheUsageWidgetShareARow(): void
    {
        self::assertSame(
            3,
            DashboardCard::SpendChart->columnSpan() + DashboardCard::BudgetUsage->columnSpan(),
        );
    }

    /**
     * The stored layout is keyed by these strings. Renaming one orphans every
     * row in `dashboard_cards` that holds it, and the account that arranged it
     * silently loses the card.
     */
    public function testTheStoredKeysAreUnchanged(): void
    {
        self::assertSame(
            ['trials', 'totals', 'spend_chart', 'budget_usage', 'recent', 'upcoming', 'per_period', 'by_category'],
            array_map(static fn (DashboardCard $card): string => $card->value, DashboardCard::cases()),
        );
    }
}
