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
 * The order of the cases is the default layout, and it is an argued one: the
 * metric row, then the year behind beside the budget it is measured against,
 * then the trials about to add to it, then the two lists.
 *
 * The year *ahead* used to lead that arrangement, and the dashboard drew both
 * charts. It draws one now. The forecast is the Analytics page's own subject —
 * its trajectory is the same payload from the same builder — and a landing
 * screen carrying two twelve-month charts was asking a reader to tell them
 * apart before either had said anything. What already happened is the one a
 * dashboard is for; what is projected is a page you go to.
 *
 * Trials is a full-width callout again with the chart it was paired with gone.
 * It led the screen once, on the argument that what is about to start costing
 * money comes before what already does, and was narrowed to sit beside the
 * year behind. There is no second chart to sit beside now, and a 2-of-6 tile
 * alone on a row is a hole rather than an arrangement.
 *
 * That ordering is what a *new* account gets. An account that has
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
    case Totals = 'totals';
    case SpendHistory = 'spend_history';
    case BudgetUsage = 'budget_usage';
    case Trials = 'trials';
    case Upcoming = 'upcoming';
    case ByCategory = 'by_category';
    case MemberShares = 'member_shares';

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
     * The chart takes two thirds rather than the whole width: twelve points
     * drawn across six columns is a picture with nothing beside it, and the
     * budget it is measured against is the natural thing to read next to the
     * spending it measures.
     *
     * A card that renders nothing leaves the rest of its row empty rather than
     * closing it up, because auto-placement is not dense. Trials is the card
     * most often absent — most households have no trial running — which is the
     * other half of why it is full width: an absent card of its own width
     * leaves an empty row of no height, where an absent narrow one leaves a
     * visible hole beside whatever it was paired with.
     *
     * Member shares is full width for the reason Trials is. The two halves
     * above it already pair with each other, so a third half-width card would
     * sit beside a hole — and it is the card most often absent, because a
     * household of one and an ISOLATED instance both give it nothing to
     * compare.
     *
     * Auto-placement is left alone rather than made dense: a dense grid would
     * reflow tiles past one another to fill holes, and a card order the user
     * chose is not something to silently improve on.
     */
    public function columnSpan(): int
    {
        return match ($this) {
            self::SpendHistory => 4,
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
