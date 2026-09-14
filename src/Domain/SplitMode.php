<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a subscription's cost is divided between household members.
 */
enum SplitMode: string
{
    /** One person bears the whole cost. */
    case None = 'none';

    /** Divided evenly between the listed participants. */
    case Equal = 'equal';

    /** Divided by the weights recorded against each participant. */
    case Custom = 'custom';

    public function isSplit(): bool
    {
        return $this !== self::None;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    public function labelKey(): string
    {
        return 'split.' . $this->value;
    }
}
