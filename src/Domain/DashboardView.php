<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which of the two dashboards a user is looking at.
 *
 * Overview answers what the month and the year cost, what is coming and where
 * the money goes; Household answers how this month is going, who pays what and
 * how the year sits against its budget. One page, two views, each with its own
 * card list — so the view is part of every layout row's key.
 *
 * Not to be confused with `LandingView`, which decides *whether* a session
 * opens on the dashboard. This decides which dashboard it is once there, and
 * is remembered per account in `users.dashboard_view`.
 *
 * The case values are stored, in that column and in `dashboard_cards.view`, so
 * they are a data format: renaming one would orphan every row holding it.
 */
enum DashboardView: string
{
    case Overview = 'overview';
    case Household = 'household';

    public function labelKey(): string
    {
        return 'dashboard_view.' . $this->value;
    }

    /**
     * Null and anything unrecognised are Overview, which is what every account
     * saw before there was a choice.
     */
    public static function fromString(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Overview;
    }
}
