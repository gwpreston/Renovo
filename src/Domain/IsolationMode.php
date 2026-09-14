<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Instance-wide data-isolation setting.
 *
 * SHARED   — every member of a household sees the household's subscriptions.
 * ISOLATED — members see only the subscriptions they own.
 *
 * The mode is applied centrally by the scoping layer. No route, service or
 * template is permitted to branch on it to decide visibility.
 */
enum IsolationMode: string
{
    case Shared = 'shared';
    case Isolated = 'isolated';

    public function restrictsToOwner(): bool
    {
        return $this === self::Isolated;
    }

    public function labelKey(): string
    {
        return 'isolation.' . $this->value;
    }
}
