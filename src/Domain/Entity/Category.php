<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final class Category
{
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly string $name,
        public readonly ?string $colour,
    ) {
    }
}
