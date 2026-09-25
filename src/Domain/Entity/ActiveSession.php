<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

/**
 * A live session row, as the security page lists it.
 *
 * The id is the session identifier, so it never reaches a template: `handle` is
 * a short prefix used as the form value, which is enough for the owner to
 * revoke a specific row and useless to anybody who sees it over their shoulder.
 */
final class ActiveSession
{
    public function __construct(
        public readonly string $handle,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $lastActivity,
        public readonly DateTimeImmutable $expiresAt,
        public readonly bool $isCurrent,
    ) {
    }

    /**
     * A readable guess at the device, from the user agent. Presentation only —
     * it is a string the client chose and nothing is decided by it.
     */
    public function deviceLabel(): string
    {
        $agent = $this->userAgent;
        if ($agent === null || trim($agent) === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown platform',
        };

        return $browser . ' on ' . $platform;
    }

    /**
     * Whether the user agent names a phone or tablet, which chooses the icon
     * drawn beside the session. Presentation only, like the label.
     */
    public function isHandheld(): bool
    {
        $agent = $this->userAgent ?? '';

        return preg_match('/iPhone|iPad|Android|Mobile/', $agent) === 1;
    }
}
