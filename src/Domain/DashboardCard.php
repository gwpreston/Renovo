<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The blocks the dashboard is made of.
 *
 * Each case is a template under `templates/dashboard/cards/`, included by name.
 * Adding a card is adding a case and a file; the page itself walks whatever
 * list it is handed and knows nothing about what is in them.
 *
 * The order of the cases is the default layout, and it is an argued one: what
 * is about to start costing money comes before what already does, and the
 * three tiles Phase 10 added sit where the design puts them — the metric row,
 * then the chart beside the usage widget, then the table — rather than at the
 * end. That ordering is what a *new* account gets. An account that has already
 * arranged its dashboard keeps its arrangement and finds the new tiles
 * appended, which is DashboardLayoutService's business and deliberately not
 * changed here: moving somebody's saved layout around to match a redesign
 * would be a worse surprise than three new tiles at the bottom.
 *
 * The case *values* are stored in `dashboard_cards.card_key`, so they are a
 * data format. Renaming one would orphan every row that holds it.
 */
enum DashboardCard: string
{
    case Trials = 'trials';
    case Totals = 'totals';
    case SpendChart = 'spend_chart';
    case BudgetUsage = 'budget_usage';
    case Recent = 'recent';
    case Upcoming = 'upcoming';
    case PerPeriod = 'per_period';
    case ByCategory = 'by_category';

    public function labelKey(): string
    {
        return 'dashboard_card.' . $this->value;
    }

    /**
     * How many of the grid's three columns this card asks for.
     *
     * The dashboard is a three-column grid that collapses to one on a narrow
     * screen, and this is what makes the design's split middle section — a
     * wide chart beside a narrow usage widget — a property of the cards rather
     * than of a fixed layout. Every other card takes the full width, so a
     * rearranged dashboard stays a legible stack.
     *
     * Auto-placement is left alone rather than made dense: a dense grid would
     * reflow tiles past one another to fill holes, and a card order the user
     * chose is not something to silently improve on.
     */
    public function columnSpan(): int
    {
        return match ($this) {
            self::SpendChart => 2,
            self::BudgetUsage => 1,
            default => 3,
        };
    }

    /**
     * @return list<self>
     */
    public static function defaultOrder(): array
    {
        return self::cases();
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
