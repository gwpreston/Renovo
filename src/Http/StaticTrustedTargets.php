<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A fixed list of trusted targets, for tests and for the empty default.
 */
final class StaticTrustedTargets implements TrustedTargets
{
    /**
     * @param list<string> $entries
     */
    public function __construct(private readonly array $entries = [])
    {
    }

    public function entries(): array
    {
        return $this->entries;
    }
}
