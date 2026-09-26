<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The rail's household label: which household this is, how many people are in
 * it, and what the reader is in it.
 *
 * A label and not a control. There is one household per session and no
 * switcher (decision 16), so this says where you are and offers nothing.
 */
final class HouseholdLabel
{
    public function __construct(
        public readonly string $name,
        public readonly int $memberCount,
        public readonly Role $role,
    ) {
    }
}
