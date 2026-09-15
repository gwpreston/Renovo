<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $passwordHash,
        public readonly bool $isInstanceAdmin,
        public readonly ?DateTimeImmutable $emailVerifiedAt,
        public readonly string $theme,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $webauthnHandle = null,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }
}
