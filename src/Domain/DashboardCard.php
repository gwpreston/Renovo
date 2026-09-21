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
 * then the chart beside the usage widget, then the two lists — rather than at
 * the end. That ordering is what a *new* account gets. An account that has
 * already arranged its dashboard keeps its arrangement and finds the new tiles
 * appended, which is DashboardLayoutService's business and deliberately not
 * changed here: moving somebody's saved layout around to match a redesign
 * would be a worse surprise than three new tiles at the bottom.
 *
 * The case *values* are stored in `dashboard_cards.card_key`, so they are a
 * data format. Renaming one would orphan every row that holds it.
 *
 * Removing one is survivable where renaming is not, because the layout is read
 * back as raw strings and merged against these cases: a row holding a key no
 * case answers to is simply passed over. That is how the per-period tile left
 * — the same four figures are the Analytics page's own subject, so the
 * dashboard was saying them twice — without a migration to chase the rows that
 * still name it. The subscriptions table left the same way and for the same
 * reason: the list, its filters and its status badges are the Subscriptions
 * page's own subject, and eight rows of it on the landing screen were a second,
 * shorter answer to a question already answered in full elsewhere.
 */
enum DashboardCard: string
{
    case Trials = 'trials';
    case Totals = 'totals';
    case SpendChart = 'spend_chart';
    case BudgetUsage = 'budget_usage';
    case Upcoming = 'upcoming';
    case ByCategory = 'by_category';

    public function labelKey(): string
    {
        return 'dashboard_card.' . $this->value;
    }

    /**
     * How many of the grid's six columns this card asks for.
     *
     * The dashboard is a six-column grid that collapses to one on a narrow
     * screen, and this is what makes the design's split rows — a wide chart
     * beside a narrow usage widget, then the renewals list beside the category
     * table — a property of the cards rather than of a fixed layout. Every
     * other card takes the full width, so a rearranged dashboard stays a
     * legible stack.
     *
     * Six rather than three because two of these rows divide differently: the
     * chart takes two thirds and the usage widget one, while Coming soon and
     * By category take half each. Three columns can express the first split
     * and has no half to give the second.
     *
     * A span is the card's own property, not the row's, so two halves make a
     * row only where they land next to each other. An account that has
     * rearranged its dashboard and put something between them gets two
     * half-width cards on separate rows, which is the honest rendering of the
     * order it chose.
     *
     * Auto-placement is left alone rather than made dense: a dense grid would
     * reflow tiles past one another to fill holes, and a card order the user
     * chose is not something to silently improve on.
     */
    public function columnSpan(): int
    {
        return match ($this) {
            self::SpendChart => 4,
            self::BudgetUsage => 2,
            self::Upcoming, self::ByCategory => 3,
            default => 6,
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
