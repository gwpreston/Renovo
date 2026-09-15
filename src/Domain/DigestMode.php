<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Whether alerts go out as they arise or are collected up.
 *
 * Digest mode is not a filter — nothing is dropped. It changes when the same
 * alerts are delivered and bundles them into one message, which for somebody
 * tracking thirty subscriptions is the difference between a useful summary and
 * a stream of individual pings.
 *
 * In digest mode lead times stop applying. A weekly digest that also honoured a
 * 30/7/1 schedule would announce the same renewal in three consecutive digests;
 * instead each digest covers the window up to the next one, so everything is
 * mentioned exactly once, shortly before it happens.
 */
enum DigestMode: string
{
    case Immediate = 'immediate';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function isDigest(): bool
    {
        return $this !== self::Immediate;
    }

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'As they happen',
            self::Weekly => 'Weekly summary',
            self::Monthly => 'Monthly summary',
        };
    }

    /**
     * How far ahead a digest looks, in days.
     *
     * A week's digest covers the coming week and a month's the coming month,
     * so that nothing falls between two issues.
     */
    public function horizonDays(): int
    {
        return match ($this) {
            self::Immediate => 0,
            self::Weekly => 7,
            self::Monthly => 31,
        };
    }

    /**
     * Whether today is the day this digest goes out.
     *
     * Weekly reads `$day` as an ISO weekday (1 = Monday). Monthly reads it as a
     * day of the month, which the preference service caps at 28 so that a
     * digest cannot be scheduled for a date February does not have.
     */
    public function isDueOn(DateTimeImmutable $today, int $day): bool
    {
        return match ($this) {
            self::Immediate => false,
            self::Weekly => (int) $today->format('N') === $day,
            self::Monthly => (int) $today->format('j') === $day,
        };
    }

    /**
     * The key identifying this issue of the digest, which is what stops a
     * second scheduler run on the same day sending it again.
     */
    public function periodKey(DateTimeImmutable $today): string
    {
        return match ($this) {
            self::Immediate => $today->format('Y-m-d'),
            self::Weekly => $today->format('o-\WW'),
            self::Monthly => $today->format('Y-m'),
        };
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
