<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The page a user lands on when they open the application.
 *
 * The dashboard is the default and the only one that needs no permission —
 * every other landing page is a view of subscription data, so a user whose
 * role cannot see subscriptions lands on the dashboard whatever they have
 * chosen. That check happens once, where the redirect is decided, rather than
 * being a rule this enum tries to remember.
 */
enum LandingView: string
{
    case Dashboard = 'dashboard';
    case Subscriptions = 'subscriptions';
    case Calendar = 'calendar';
    case Budgets = 'budgets';
    case Forecast = 'forecast';
    case Stats = 'stats';

    public function labelKey(): string
    {
        return 'landing.' . $this->value;
    }

    public function path(): string
    {
        return match ($this) {
            self::Dashboard => '/',
            self::Subscriptions => '/subscriptions',
            self::Calendar => '/calendar',
            self::Budgets => '/budgets',
            self::Forecast => '/forecast',
            self::Stats => '/stats',
        };
    }

    /**
     * Whether landing here means reading subscription data.
     */
    public function needsSubscriptionAccess(): bool
    {
        return $this !== self::Dashboard;
    }

    public static function fromString(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Dashboard;
    }
}
