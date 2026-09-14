<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuthAttemptRepository;
use App\Support\Clock;

/**
 * Progressive throttling for login and password reset.
 *
 * Two independent limits apply, and either one being exceeded blocks the
 * attempt: per account (so one account cannot be brute-forced from many
 * addresses) and per IP (so one address cannot spray many accounts).
 *
 * Blocking is time-boxed rather than permanent: a locked-out legitimate user
 * gets back in by waiting, without an administrator having to intervene.
 */
final class RateLimiter
{
    public function __construct(
        private readonly AuthAttemptRepository $attempts,
        private readonly Clock $clock,
        private readonly int $maxPerAccount,
        private readonly int $maxPerIp,
        private readonly int $windowSeconds,
        private readonly int $lockoutSeconds,
    ) {
    }

    public function isBlocked(string $kind, string $accountKey, string $ipAddress): bool
    {
        return $this->remainingLockoutSeconds($kind, $accountKey, $ipAddress) > 0;
    }

    /**
     * @return int Seconds until the caller may try again; 0 when not blocked.
     */
    public function remainingLockoutSeconds(string $kind, string $accountKey, string $ipAddress): int
    {
        $since = $this->clock->now()->modify(sprintf('-%d seconds', $this->windowSeconds));

        $accountFailures = $accountKey === ''
            ? 0
            : $this->attempts->countFailuresForAccount($kind, $accountKey, $since);
        $ipFailures = $this->attempts->countFailuresForIp($kind, $ipAddress, $since);

        if ($accountFailures < $this->maxPerAccount && $ipFailures < $this->maxPerIp) {
            return 0;
        }

        return $this->lockoutSeconds;
    }

    public function recordFailure(string $kind, string $accountKey, string $ipAddress): void
    {
        $this->attempts->record($kind, $accountKey, $ipAddress, false);
    }

    public function recordSuccess(string $kind, string $accountKey, string $ipAddress): void
    {
        $this->attempts->record($kind, $accountKey, $ipAddress, true);
        if ($accountKey !== '') {
            $this->attempts->clearForAccount($kind, $accountKey);
        }
    }
}
