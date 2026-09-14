<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Injected wherever "now" matters, so tests can pin the date rather than
 * skipping assertions that would be flaky near midnight or a month boundary.
 */
interface Clock
{
    public function now(): DateTimeImmutable;

    /**
     * Today at midnight UTC — the reference point for renewal windows.
     */
    public function today(): DateTimeImmutable;
}
