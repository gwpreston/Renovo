<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

/**
 * A named set of list filters.
 *
 * The query string is kept exactly as the list produced it and is never
 * interpreted here — SubscriptionFilter parses it back, through the same
 * allow-lists it applies to a URL.
 */
final class SavedView
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly ?int $householdId,
        public readonly string $name,
        public readonly string $queryString,
        public readonly int $position,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * The address this view points at.
     */
    public function path(): string
    {
        return '/subscriptions' . ($this->queryString === '' ? '' : '?' . $this->queryString);
    }
}
