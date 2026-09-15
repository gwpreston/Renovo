<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Repository\NotificationLogRepository;
use App\Support\Clock;

/**
 * A ceiling on how much outbound traffic one user can cause.
 *
 * The SSRF guard decides *where* a request may go; this decides *how many*.
 * They are different failures: a webhook pointed at a third party turns this
 * instance into a small amplifier, and a bug in an alert rule — a date read
 * wrong, a loop that re-fires — turns it into a large one. Neither is caught by
 * checking addresses.
 *
 * It counts the ledger rather than keeping its own tally, because the ledger
 * already records one row per delivery attempt with the user and the subject on
 * it. A second counter would be a second thing to keep in step.
 *
 * The per-subject limit is the one that catches the realistic mistake: a single
 * subscription cannot produce more than a handful of notifications a day
 * however many channels and alert types conspire.
 */
final class NotificationRateLimiter
{
    public function __construct(
        private readonly NotificationLogRepository $log,
        private readonly Clock $clock,
        private readonly int $perUserPerHour = 60,
        private readonly int $perSubjectPerDay = 20,
    ) {
    }

    public function allows(int $userId, int $subjectId): bool
    {
        $now = $this->clock->now();

        if ($this->log->countSince($userId, $now->modify('-1 hour')) >= $this->perUserPerHour) {
            return false;
        }

        if ($subjectId <= 0) {
            return true;
        }

        return $this->log->countSince($userId, $now->modify('-1 day'), $subjectId) < $this->perSubjectPerDay;
    }
}
