<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One cell of the role matrix: what a role may do with one capability.
 *
 * Each carries a word and an icon as well as a colour, because a table that
 * said "no" only in grey would say nothing to a reader who cannot see grey.
 */
enum CapabilityGrant: string
{
    case Yes = 'yes';
    case OwnOnly = 'own_only';
    case No = 'no';

    public function labelKey(): string
    {
        return 'capability_grant.' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Yes => 'check',
            self::OwnOnly => 'user-edit',
            self::No => 'minus',
        };
    }
}
