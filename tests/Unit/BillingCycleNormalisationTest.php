<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\BillingCycle;
use App\Domain\Rounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The normalisation factors are a decision, not an implementation detail:
 * every historical figure the dashboard shows depends on them. These tests
 * pin them so a change has to be deliberate.
 */
final class BillingCycleNormalisationTest extends TestCase
{
    public function testCalendarCyclesUseExactIntegerFactors(): void
    {
        // £10 monthly is £120 a year and £10 a month, with no drift.
        self::assertSame(12000, BillingCycle::Monthly->annualMinor(1000));
        self::assertSame(1000, BillingCycle::Monthly->monthlyMinor(1000));

        self::assertSame(4000, BillingCycle::Quarterly->annualMinor(1000));
        self::assertSame(333, BillingCycle::Quarterly->monthlyMinor(1000));

        self::assertSame(1000, BillingCycle::Yearly->annualMinor(1000));
        self::assertSame(83, BillingCycle::Yearly->monthlyMinor(1000));
    }

    public function testDayBasedCyclesUseA365Point25DayYear(): void
    {
        // 365.25 / 7 = 52.178… payments a year. £10 weekly is £521.79 a year.
        self::assertSame(52179, BillingCycle::Weekly->annualMinor(1000));
        self::assertSame(4348, BillingCycle::Weekly->monthlyMinor(1000));

        // A 7-day custom cycle must agree exactly with the weekly cycle.
        self::assertSame(
            BillingCycle::Weekly->annualMinor(1000),
            BillingCycle::CustomDays->annualMinor(1000, 7),
        );

        // A 28-day cycle is not the same as monthly, and the difference is the
        // point of offering it.
        self::assertSame(13045, BillingCycle::CustomDays->annualMinor(1000, 28));
        self::assertNotSame(
            BillingCycle::Monthly->annualMinor(1000),
            BillingCycle::CustomDays->annualMinor(1000, 28),
        );

        // A 365-day cycle comes round fractionally more than once a year,
        // which is the honest consequence of using a 365.25-day year rather
        // than a rounding error.
        self::assertSame(1001, BillingCycle::CustomDays->annualMinor(1000, 365));
        self::assertSame(1000, BillingCycle::Yearly->annualMinor(1000));
    }

    public function testMonthlyIsAlwaysDerivedFromTheAnnualFigure(): void
    {
        // The two columns of a report must agree with each other; deriving
        // both from the same annual number is what guarantees that.
        foreach ([1, 99, 999, 123456] as $price) {
            foreach (BillingCycle::cases() as $cycle) {
                $days = $cycle->requiresCycleDays() ? 30 : null;

                self::assertSame(
                    Rounding::divide($cycle->annualMinor($price, $days), 12),
                    $cycle->monthlyMinor($price, $days),
                    sprintf('%s at %d minor units', $cycle->value, $price),
                );
            }
        }
    }

    public function testCustomCycleRequiresAPositiveDayCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BillingCycle::CustomDays->annualMinor(1000, 0);
    }

    /**
     * @return list<array{int, int, int}>
     */
    public static function roundingProvider(): array
    {
        return [
            [10, 4, 3],   // 2.5 rounds away from zero
            [-10, 4, -3],
            [7, 2, 4],    // 3.5 -> 4
            [-7, 2, -4],
            [5, 3, 2],    // 1.67 -> 2
            [1, 3, 0],
            [0, 5, 0],
        ];
    }

    #[DataProvider('roundingProvider')]
    public function testDivisionRoundsHalfAwayFromZero(int $dividend, int $divisor, int $expected): void
    {
        self::assertSame($expected, Rounding::divide($dividend, $divisor));
    }
}
