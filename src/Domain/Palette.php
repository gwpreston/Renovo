<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which of the five colour palettes the interface wears.
 *
 * A palette sets the rail and the accent; the surfaces beneath are shared, and
 * light or dark is a separate choice (`Theme`). The cases are the allowlist:
 * a value reaches the `data-palette` attribute on the page only by being one
 * of them, so a tampered form cannot write an arbitrary string into the
 * markup. Their colours are defined in `assets/theme/tokens.json` under the
 * same keys, and a test holds the two lists together.
 *
 * Null — an account that has never chosen — means navy. That rule is
 * `fromNullable()` and nowhere else, which is the seam an instance-wide
 * default would replace.
 */
enum Palette: string
{
    case Navy = 'navy';
    case Paper = 'paper';
    case Midnight = 'midnight';
    case Ocean = 'ocean';
    case Forest = 'forest';

    public function labelKey(): string
    {
        return 'palette.' . $this->value;
    }

    /** What an account with no choice gets, and what a signed-out page wears. */
    public static function default(): self
    {
        return self::Navy;
    }

    /**
     * The stored value, resolved. Null and anything unrecognised both come
     * back as the default: a column somebody edited by hand should not be able
     * to leave a page unstyled.
     */
    public static function fromNullable(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::default();
    }
}
