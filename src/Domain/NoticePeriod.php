<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How much notice must be given to cancel — a first-class field, because the
 * date that actually matters to a user is not the renewal date but the last
 * day they can still get out before it.
 *
 * Stored as an amount plus a unit rather than a day count so that "one month"
 * stays one calendar month rather than silently becoming 30 days.
 */
final class NoticePeriod
{
    public const UNIT_DAYS = 'days';
    public const UNIT_WEEKS = 'weeks';
    public const UNIT_MONTHS = 'months';

    private const UNITS = [self::UNIT_DAYS, self::UNIT_WEEKS, self::UNIT_MONTHS];

    private function __construct(
        public readonly ?int $amount,
        public readonly string $unit,
    ) {
    }

    public static function none(): self
    {
        return new self(null, self::UNIT_DAYS);
    }

    public static function of(?int $amount, ?string $unit): self
    {
        if ($amount === null || $amount <= 0) {
            return self::none();
        }

        $unit = $unit ?? self::UNIT_DAYS;
        if (!in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown notice-period unit "%s".', $unit));
        }

        return new self($amount, $unit);
    }

    public function isSet(): bool
    {
        return $this->amount !== null;
    }

    /**
     * @return list<string>
     */
    public static function units(): array
    {
        return self::UNITS;
    }

    /**
     * The latest date on which notice can still be given for a charge falling
     * on $paymentDate.
     */
    public function deadlineBefore(DateTimeImmutable $paymentDate): ?DateTimeImmutable
    {
        if ($this->amount === null) {
            return null;
        }

        return match ($this->unit) {
            self::UNIT_DAYS => $paymentDate->modify(sprintf('-%d days', $this->amount)),
            self::UNIT_WEEKS => $paymentDate->modify(sprintf('-%d days', $this->amount * 7)),
            self::UNIT_MONTHS => BillingCycle::addMonths($paymentDate, -$this->amount),
            default => null,
        };
    }

    public function labelKey(): string
    {
        return 'notice.' . $this->unit;
    }
}
