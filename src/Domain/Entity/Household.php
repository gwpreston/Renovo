<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final class Household
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $createdByUserId,
    ) {
    }
}
