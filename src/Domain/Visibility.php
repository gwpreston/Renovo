<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who a subscription is shown to.
 *
 * `Payer` hides a row from everybody in the household but its owner, in either
 * isolation mode and whatever their role. It is applied by the scoping layer
 * (`AbstractScopedRepository::privacyPredicate()`), never by a screen, so a
 * private row is absent from every list, total and feed another member can
 * reach rather than merely undrawn.
 */
enum Visibility: string
{
    case Household = 'household';
    case Payer = 'payer';

    public function isPrivate(): bool
    {
        return $this === self::Payer;
    }

    public function labelKey(): string
    {
        return 'visibility.' . $this->value;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
