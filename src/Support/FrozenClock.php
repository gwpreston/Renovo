<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * A clock that does not move. Test-only, but it lives in src so that it can be
 * wired into the container when reproducing a date-dependent bug.
 */
final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public static function at(string $dateTime): self
    {
        return new self(new DateTimeImmutable($dateTime));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function today(): DateTimeImmutable
    {
        return $this->now->setTime(0, 0, 0);
    }

    public function advanceTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
