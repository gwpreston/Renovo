<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    /**
     * The timezone is injected rather than defaulted in the signature: a `new`
     * expression as a parameter default cannot be compiled by the container,
     * which turns into a fatal error the moment compilation is enabled — that
     * is, in production and nowhere else.
     */
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }

    public function today(): DateTimeImmutable
    {
        return $this->now()->setTime(0, 0, 0);
    }
}
