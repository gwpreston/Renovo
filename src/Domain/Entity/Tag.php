<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final class Tag
{
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly string $name,
    ) {
    }
}
