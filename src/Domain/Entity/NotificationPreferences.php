<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\DigestMode;

/**
 * A user's notification settings, with defaults that are the settings most
 * people would have chosen anyway.
 *
 * Thirty, seven and one day before a charge: far enough out to cancel something
 * annual, close enough to act on, and one last word the day before. A user who
 * never opens this screen still gets sensible reminders, which is the point —
 * a feature that only works once configured mostly does not work.
 */
final class NotificationPreferences
{
    /** @var list<int> */
    public readonly array $leadDays;

    /**
     * @param list<int> $leadDays
     */
    public function __construct(
        public readonly int $userId,
        array $leadDays,
        public readonly DigestMode $digestMode,
        public readonly int $digestDay,
        /** Whether to be told when a price is changed or scheduled. On unless turned off. */
        public readonly bool $priceChangeAlerts = true,
    ) {
        $days = array_values(array_unique(array_filter($leadDays, static fn (int $d): bool => $d >= 0)));
        rsort($days);

        $this->leadDays = $days;
    }

    public static function defaults(int $userId): self
    {
        return new self($userId, [30, 7, 1], DigestMode::Immediate, 1);
    }

    /**
     * @return list<int>
     */
    public static function parseLeadDays(string $value): array
    {
        $days = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $days[] = (int) $part;
            }
        }

        return $days;
    }

    public function leadDaysAsString(): string
    {
        return implode(',', $this->leadDays);
    }
}
