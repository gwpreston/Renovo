<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Role;

final class Membership
{
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly int $userId,
        public readonly Role $role,
        public readonly ?string $householdName = null,
    ) {
    }
}
