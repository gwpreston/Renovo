<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How often a subscription is billed.
 *
 * Normalisation to a monthly or yearly figure is integer-only. Cycles that are
 * defined in days (weekly, custom-N-days) do not divide evenly into a year, so
 * a year is taken to be 365.25 days — the Gregorian mean including leap years.
 * That factor is fixed here and asserted by the test suite: changing it changes
 * every historical statistic, so it is a documented decision, not an accident.
 *
 * Weekly, monthly and yearly take an interval — every N weeks, months or years
 * — so a six-monthly policy advances by calendar months and stays on its
 * anchor day, which a custom 182 days would not. Quarterly and custom days
 * always run on an interval of 1: quarterly *is* every three months, and a
 * custom cycle's day count is its interval already. An interval of 1 gives
 * exactly the figures every cycle gave before intervals existed.
 */
enum BillingCycle: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case CustomDays = 'custom_days';

    /** Mean Gregorian year length, scaled by 100 to stay in integers. */
    public const DAYS_PER_YEAR_X100 = 36525;

    /**
     * The anchor day that means "the last day of the month": `addMonths()`
     * clamps it to each month's own length.
     */
    public const LAST_DAY_ANCHOR = 31;

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    public function requiresCycleDays(): bool
    {
        return $this === self::CustomDays;
    }

    /**
     * Whether this cycle can run every N units rather than every one.
     */
    public function allowsInterval(): bool
    {
        return $this->maxInterval() > 1;
    }

    /**
     * The largest interval this cycle accepts. Beyond it a custom number of
     * days, or a one-off, is the honest model.
     */
    public function maxInterval(): int
    {
        return match ($this) {
            self::Weekly => 52,
            self::Monthly => 24,
            self::Yearly => 10,
            self::Quarterly, self::CustomDays => 1,
        };
    }

    public function isValidInterval(int $interval): bool
    {
        return $interval >= 1 && $interval <= $this->maxInterval();
    }

    /**
     * Reject an interval this cycle cannot run on. Never clamps: a stored
     * every-30-months row is a bug to surface, not a figure to quietly change.
     */
    public function assertInterval(?int $interval): int
    {
        $interval ??= 1;
        if (!$this->isValidInterval($interval)) {
            throw new InvalidArgumentException(sprintf(
                'A %s cycle takes an interval from 1 to %d; %d given.',
                $this->value,
                $this->maxInterval(),
                $interval,
            ));
        }

        return $interval;
    }

    public function labelKey(): string
    {
        return 'cycle.' . $this->value;
    }

    /**
     * The equivalent cost over one year, in minor units.
     *
     * @param int      $priceMinor Price charged once per cycle.
     * @param int|null $cycleDays  Required for the custom-days cycle.
     * @param int|null $interval   Every N weeks, months or years; null is 1.
     */
    public function annualMinor(int $priceMinor, ?int $cycleDays = null, ?int $interval = null): int
    {
        $interval = $this->assertInterval($interval);

        return match ($this) {
            self::Monthly => Rounding::multiplyDivide($priceMinor, 12, $interval),
            self::Quarterly => $priceMinor * 4,
            self::Yearly => Rounding::divide($priceMinor, $interval),
            self::Weekly => Rounding::multiplyDivide($priceMinor, self::DAYS_PER_YEAR_X100, 7 * 100 * $interval),
            self::CustomDays => Rounding::multiplyDivide(
                $priceMinor,
                self::DAYS_PER_YEAR_X100,
                $this->assertCycleDays($cycleDays) * 100,
            ),
        };
    }

    /**
     * The equivalent cost over one month, in minor units.
     *
     * Always derived from the annual figure so that the monthly and yearly
     * columns of a report are consistent with one another.
     */
    public function monthlyMinor(int $priceMinor, ?int $cycleDays = null, ?int $interval = null): int
    {
        return Rounding::divide($this->annualMinor($priceMinor, $cycleDays, $interval), 12);
    }

    /**
     * Advance a payment date by exactly one cycle.
     *
     * Calendar cycles clamp to the end of the target month: 31 January plus one
     * month is 28/29 February. To stop a subscription billed on the 31st from
     * permanently drifting to the 28th, callers pass the original anchor day
     * (the day-of-month the subscription was first billed on) and it is
     * restored whenever the target month is long enough. An anchor of 31 is
     * "the last day of the month": every month is clamped to its own end.
     */
    public function advance(
        DateTimeImmutable $from,
        ?int $cycleDays = null,
        ?int $anchorDay = null,
        ?int $interval = null,
    ): DateTimeImmutable {
        $interval = $this->assertInterval($interval);

        return match ($this) {
            self::Weekly => $from->modify('+' . (7 * $interval) . ' days'),
            self::CustomDays => $from->modify('+' . $this->assertCycleDays($cycleDays) . ' days'),
            self::Monthly => self::addMonths($from, $interval, $anchorDay),
            self::Quarterly => self::addMonths($from, 3, $anchorDay),
            self::Yearly => self::addMonths($from, 12 * $interval, $anchorDay),
        };
    }

    /**
     * Add whole months without the overflow PHP's "+1 month" performs, which
     * would turn 31 January into 3 March.
     */
    public static function addMonths(DateTimeImmutable $from, int $months, ?int $anchorDay = null): DateTimeImmutable
    {
        $day = $anchorDay ?? (int) $from->format('j');
        $year = (int) $from->format('Y');
        $month = (int) $from->format('n') + $months;

        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;
        if ($month < 1) {
            $month += 12;
            $year--;
        }

        $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return $from->setDate($year, $month, min($day, $daysInMonth));
    }

    private function assertCycleDays(?int $cycleDays): int
    {
        if ($cycleDays === null || $cycleDays < 1) {
            throw new InvalidArgumentException('A custom cycle requires a positive number of days.');
        }

        return $cycleDays;
    }
}
