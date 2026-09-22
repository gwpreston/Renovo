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
     *
     * Asserted as a sum rather than as two numbers because that is the claim —
     * either card could be widened as long as the other gave the width back,
     * and a row that adds up to seven silently becomes two rows.
     */
    public function testTheChartAndTheUsageWidgetShareARow(): void
    {
        self::assertSame(
            6,
            DashboardCard::SpendHistory->columnSpan() + DashboardCard::BudgetUsage->columnSpan(),
        );
    }

    /**
     * A row is only a row where the two cards are next to each other, so the
     * default order has to put them there. This is the assertion that fails if
     * a card is ever inserted between the chart and the widget it shares its
     * width with.
     */
    public function testTheDefaultOrderPutsTheUsageWidgetBesideTheChart(): void
    {
        $order = DashboardCard::defaultOrder();

        self::assertSame(
            array_search(DashboardCard::SpendHistory, $order, true) + 1,
            array_search(DashboardCard::BudgetUsage, $order, true),
            'budget_usage does not directly follow spend_history',
        );
    }

    /**
     * Every row of the default arrangement adds up to the full six.
     *
     * The spans are what make the rows, and with one chart rather than two
     * they only tile if Trials is full width — 6, then 4 + 2, then 6, then
     * 3 + 3. Narrow it again without giving it a partner and the grid gains a
     * hole that nothing in the CSS or the enum would complain about, which is
     * exactly the kind of wrong that ships.
     */
    public function testTheDefaultOrderTilesIntoWholeRows(): void
    {
        $row = 0;

        foreach (DashboardCard::defaultOrder() as $card) {
            $row += $card->columnSpan();

            self::assertLessThanOrEqual(6, $row, 'the row holding ' . $card->value . ' overflows the grid');

            $row %= 6;
        }

        self::assertSame(0, $row, 'the last row of the default arrangement is unfinished');
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
     * `spend_history` took the slot `spend_chart` held, and `trials` is a
     * full-width callout again now that the chart it sat beside has gone. The
     * order is a default, so an account that has already arranged its
     * dashboard keeps its own — `DashboardLayoutService`'s rule, and the
     * reason moving a case here is a change of first impression rather than a
     * change to anybody's saved screen.
     *
     * `member_shares` is last because it is newest: appending is what lets an
     * account that has already arranged its dashboard keep its arrangement and
     * find the new tile at the bottom, rather than have the redesign shuffle
     * the screen it chose.
     *
     * `per_period`, `recent` and now `spend_chart` are deliberately absent:
     * each tile was removed because another screen is the same subject's home
     * — the Analytics page for the four per-period figures and for the year
     * ahead, the Subscriptions page for the list — and rows still naming them
     * are passed over rather than migrated away.
     */
    public function testTheStoredKeysAreUnchanged(): void
    {
        self::assertSame(
            ['totals', 'spend_history', 'budget_usage', 'trials', 'upcoming', 'by_category', 'member_shares'],
            array_map(static fn (DashboardCard $card): string => $card->value, DashboardCard::cases()),
        );
    }

    /**
     * A card that left is a key that must never come back as something else.
     *
     * `spend_chart` rows are still in `dashboard_cards` on any instance whose
     * users had arranged their dashboard, and they are passed over because no
     * case answers to the string. Re-using it for a different card would hand
     * those accounts a tile they never chose, in a position they did choose.
     */
    public function testARetiredKeyIsNotReused(): void
    {
        self::assertNull(DashboardCard::tryFromString('spend_chart'));
    }
}
