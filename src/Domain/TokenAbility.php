<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What an API token may be used for.
 *
 * Two values rather than a permission list, and that is the whole point: a
 * token narrows, it never grants. The scope a request runs under is still built
 * from the bearer's household membership, so a write token held by a Viewer
 * writes nothing — it simply fails the same permission check the browser would.
 * What the ability adds is the ability to issue a credential that is *weaker*
 * than the account it belongs to, which is what makes it safe to paste into a
 * calendar client or a read-only script.
 */
enum TokenAbility: string
{
    case Read = 'read';
    case Write = 'write';

    public function allowsWrites(): bool
    {
        return $this === self::Write;
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read-only',
            self::Write => 'Read and write',
        };
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Read;
    }
}
