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
     * The grid is six columns, and the stylesheet has a class for each span
     * the design actually uses. A span inside the grid but without a class —
     * five, say — would not fail on width; it would silently fall through to
     * the full-width default, which is why this asserts the set rather than a
     * range.
     */
    public function testEverySpanHasAClassInTheStylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/screens.css');

        foreach (DashboardCard::cases() as $card) {
            self::assertStringContainsString(
                '.bento-span-' . $card->columnSpan() . ' {',
                $css,
                $card->value . ' asks for a span the stylesheet does not define',
            );
        }
    }

    /**
     * The design's split middle section: the chart and the usage widget are a
     * row between them, which is the whole reason a card declares a span.
     */
    public function testTheChartAndTheUsageWidgetShareARow(): void
    {
        self::assertSame(
            6,
            DashboardCard::SpendChart->columnSpan() + DashboardCard::BudgetUsage->columnSpan(),
        );
    }

    /**
     * And the same arrangement for the year behind: a two-thirds chart with
     * the trials callout beside it.
     *
     * Asserted as a sum rather than as two numbers because that is the claim —
     * either card could be widened as long as the other gave the width back,
     * and a row that adds up to seven silently becomes two rows.
     */
    public function testTheHistoryChartAndTheTrialsCalloutShareARow(): void
    {
        self::assertSame(
            6,
            DashboardCard::SpendHistory->columnSpan() + DashboardCard::Trials->columnSpan(),
        );
    }

    /**
     * A row is only a row where the two cards are next to each other, so the
     * default order has to put them there. This is the assertion that fails if
     * a card is ever inserted between a chart and the narrow card it shares
     * its width with.
     */
    public function testTheDefaultOrderPutsEachChartBesideItsOwnNarrowCard(): void
    {
        $order = DashboardCard::defaultOrder();

        foreach ([[DashboardCard::SpendChart, DashboardCard::BudgetUsage],
            [DashboardCard::SpendHistory, DashboardCard::Trials]] as [$chart, $beside]) {
            self::assertSame(
                array_search($chart, $order, true) + 1,
                array_search($beside, $order, true),
                $beside->value . ' does not directly follow ' . $chart->value,
            );
        }
    }

    /**
     * The second split row: the renewals list and the category table are half
     * the grid each. This is the assertion that would fail if the grid went
     * back to three columns, where there is no half to give them.
     */
    public function testComingSoonAndByCategoryShareARow(): void
    {
        self::assertSame(
            3,
            DashboardCard::Upcoming->columnSpan(),
            'Coming soon should be half the grid',
        );
        self::assertSame(
            3,
            DashboardCard::ByCategory->columnSpan(),
            'By category should be half the grid',
        );
    }

    /**
     * The stored layout is keyed by these strings. Renaming one orphans every
     * row in `dashboard_cards` that holds it, and the account that arranged it
     * silently loses the card.
     *
     * `spend_history` joined the list rather than replacing anything, and
     * `trials` moved from the head of it to sit beside the new chart. The
     * order is a default, so an account that has already arranged its
     * dashboard keeps its own — `DashboardLayoutService`'s rule, and the
     * reason moving a case here is a change of first impression rather than a
     * change to anybody's saved screen.
     *
     * `per_period` and `recent` are deliberately absent: both tiles were
     * removed because another screen is the same subject's home — the
     * Analytics page for the four per-period figures, the Subscriptions page
     * for the list — and rows still naming them are passed over rather than
     * migrated away.
     */
    public function testTheStoredKeysAreUnchanged(): void
    {
        self::assertSame(
            ['totals', 'spend_chart', 'budget_usage', 'spend_history', 'trials', 'upcoming', 'by_category'],
            array_map(static fn (DashboardCard $card): string => $card->value, DashboardCard::cases()),
        );
    }
}
