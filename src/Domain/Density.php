<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How much room a list gives each row.
 *
 * Comfortable is the default because a first-time reader is scanning, not
 * auditing. Compact is for the account with ninety subscriptions, where the
 * useful question is "what is due this week" and every row that fits on the
 * screen is one less scroll.
 *
 * The preference is applied as an attribute on `<body>` and the difference is
 * entirely CSS: no template renders a different table.
 */
enum Density: string
{
    case Comfortable = 'comfortable';
    case Compact = 'compact';

    public function labelKey(): string
    {
        return 'density.' . $this->value;
    }

    public static function fromString(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Comfortable;
    }
}
