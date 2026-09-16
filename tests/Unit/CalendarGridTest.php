<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\CalendarGrid;
use App\Domain\WeekStart;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The month grid, and the preference that decides its shape.
 *
 * Asserted here rather than by rendering a page: the week start is two lines
 * of modular arithmetic, and it is wrong in a way nobody notices until a
 * Sunday-first reader finds their month starting on the wrong column.
 */
final class CalendarGridTest extends TestCase
{
    public function testAMondayFirstGridPutsTheFirstOfTheMonthUnderItsWeekday(): void
    {
        // 1 September 2026 is a Tuesday.
        $weeks = CalendarGrid::build(2026, 9, WeekStart::Monday);

        self::assertNull($weeks[0][0], 'Monday is blank.');
        self::assertSame('2026-09-01', $weeks[0][1]?->format('Y-m-d'), 'Tuesday holds the first.');
    }

    public function testTheSameMonthShiftsWhenWeeksStartOnSunday(): void
    {
        $weeks = CalendarGrid::build(2026, 9, WeekStart::Sunday);

        self::assertNull($weeks[0][0], 'Sunday is blank.');
        self::assertNull($weeks[0][1], 'Monday is blank.');
        self::assertSame('2026-09-01', $weeks[0][2]?->format('Y-m-d'), 'Tuesday has moved one column right.');
    }

    /**
     * The case the modulo exists for: a month starting on the very day the
     * week starts needs no blanks at all, and an off-by-one here would push
     * the whole month a week down.
     */
    public function testAMonthStartingOnTheWeekStartHasNoLeadingBlanks(): void
    {
        // 1 June 2026 is a Monday.
        self::assertSame('2026-06-01', CalendarGrid::build(2026, 6, WeekStart::Monday)[0][0]?->format('Y-m-d'));

        // 1 February 2026 is a Sunday.
        self::assertSame('2026-02-01', CalendarGrid::build(2026, 2, WeekStart::Sunday)[0][0]?->format('Y-m-d'));
    }

    public function testEveryWeekHasSevenCellsAndEveryDayAppearsOnce(): void
    {
        foreach ([WeekStart::Monday, WeekStart::Sunday] as $weekStart) {
            foreach ([[2026, 2], [2024, 2], [2026, 5], [2026, 8], [2026, 12]] as [$year, $month]) {
                $weeks = CalendarGrid::build($year, $month, $weekStart);

                $days = [];
                foreach ($weeks as $week) {
                    self::assertCount(7, $week, 'Every row is a whole week.');
                    foreach ($week as $date) {
                        if ($date instanceof DateTimeImmutable) {
                            $days[] = $date->format('Y-m-d');
                        }
                    }
                }

                $expected = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

                self::assertCount($expected, $days, sprintf('%04d-%02d has every one of its days.', $year, $month));
                self::assertSame($days, array_unique($days), 'No day appears twice.');
                self::assertSame($days, array_values(array_filter($days)), 'The days are in order.');
            }
        }
    }

    public function testALeapDayIsOnTheGrid(): void
    {
        $days = [];
        foreach (CalendarGrid::build(2024, 2, WeekStart::Monday) as $week) {
            foreach ($week as $date) {
                if ($date !== null) {
                    $days[] = $date->format('Y-m-d');
                }
            }
        }

        self::assertContains('2024-02-29', $days);
    }

    public function testTheWeekdayHeadingsFollowThePreference(): void
    {
        $monday = array_map(
            static fn (DateTimeImmutable $day): string => $day->format('D'),
            CalendarGrid::weekdays(WeekStart::Monday),
        );
        $sunday = array_map(
            static fn (DateTimeImmutable $day): string => $day->format('D'),
            CalendarGrid::weekdays(WeekStart::Sunday),
        );

        self::assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], $monday);
        self::assertSame(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], $sunday);
    }
}
