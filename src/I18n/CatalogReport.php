<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * How one locale's catalogue compares with the base.
 */
final class CatalogReport
{
    /**
     * @param list<string> $missing Keys the base has and this locale does not.
     * @param list<string> $extra   Keys this locale has and the base does not.
     */
    public function __construct(
        public readonly string $locale,
        public readonly array $missing,
        public readonly array $extra,
        public readonly int $total,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->missing === [] && $this->extra === [];
    }

    public function translated(): int
    {
        return $this->total - count($this->missing);
    }
}
