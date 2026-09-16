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
 * is about to start costing money comes before what already does.
 */
enum DashboardCard: string
{
    case Trials = 'trials';
    case Totals = 'totals';
    case Upcoming = 'upcoming';
    case PerPeriod = 'per_period';
    case ByCategory = 'by_category';

    public function labelKey(): string
    {
        return 'dashboard_card.' . $this->value;
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
