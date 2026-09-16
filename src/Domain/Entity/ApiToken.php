<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\TokenAbility;
use DateTimeImmutable;

/**
 * An issued API token, as the settings page sees it.
 *
 * The secret is not here and cannot be: only its hash was ever stored. What the
 * UI shows is the public id, which identifies the token without authenticating
 * anything — enough to recognise which one to revoke, useless to anybody who
 * reads it over your shoulder.
 */
final class ApiToken
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly ?int $householdId,
        public readonly string $name,
        public readonly string $publicId,
        public readonly TokenAbility $abilities,
        public readonly ?DateTimeImmutable $lastUsedAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $revokedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->hasExpired($now);
    }
}
