<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which palette the interface uses.
 *
 * "System" is the default and follows the browser's own preference, so an
 * account that has never been near the settings page still matches the rest of
 * the desktop it is being read on.
 */
enum Theme: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public function labelKey(): string
    {
        return 'theme.' . $this->value;
    }

    public static function fromString(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::System;
    }
}
