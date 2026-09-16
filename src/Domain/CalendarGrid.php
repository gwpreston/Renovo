<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * The shape of a month as a calendar draws it: whole weeks, with the days
 * before the first and after the last left empty.
 *
 * Pure arithmetic, deliberately. Which day a week starts on is a preference,
 * and a preference that only ever gets exercised by rendering a page is a
 * preference nobody can test cheaply — so the part that can be got wrong lives
 * here, where it is a function of two integers, and the template only walks
 * what it is given.
 */
final class CalendarGrid
{
    public const DAYS_IN_WEEK = 7;

    /**
     * The weeks of a month, each a list of seven days, with null for a cell
     * that belongs to a neighbouring month.
     *
     * @return list<list<DateTimeImmutable|null>>
     */
    public static function build(int $year, int $month, WeekStart $weekStart): array
    {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $daysInMonth = (int) $first->format('t');

        $cells = array_fill(0, $weekStart->leadingBlanks((int) $first->format('w')), null);

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $cells[] = $first->modify(sprintf('+%d days', $day - 1));
        }

        // Pad to a whole number of weeks so every row has seven cells and the
        // template needs no arithmetic of its own.
        while (count($cells) % self::DAYS_IN_WEEK !== 0) {
            $cells[] = null;
        }

        return array_values(array_map(
            static fn (array $week): array => array_values($week),
            array_chunk($cells, self::DAYS_IN_WEEK),
        ));
    }

    /**
     * The seven weekday headings, as dates a template can format in the
     * reader's own locale rather than as English names baked in here.
     *
     * Any week containing all seven days will do; a fixed reference week keeps
     * the result independent of when the page was rendered.
     *
     * @return list<DateTimeImmutable>
     */
    public static function weekdays(WeekStart $weekStart): array
    {
        // A Sunday, so that offset 0 is weekday 0.
        $reference = new DateTimeImmutable('2024-01-07 00:00:00');

        return array_map(
            static fn (int $weekday): DateTimeImmutable => $reference->modify(sprintf('+%d days', $weekday)),
            $weekStart->days(),
        );
    }
}
