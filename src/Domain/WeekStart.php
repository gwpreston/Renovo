<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which day a calendar week begins on.
 *
 * Not derivable from the locale, which is why it is a preference rather than a
 * lookup: `en_GB` and `en_US` disagree, `fr` and `de` start on Monday, and
 * plenty of people read a Monday-first calendar in an American locale because
 * that is how their working week runs. ICU has an answer for every locale and
 * it is the wrong answer often enough to be worth asking.
 *
 * Stored as the day number PHP's `format('w')` uses — Sunday 0, Monday 1 — so
 * the calendar's leading-blank arithmetic is a subtraction and a modulo.
 */
enum WeekStart: int
{
    case Sunday = 0;
    case Monday = 1;

    public function labelKey(): string
    {
        return 'week_start.' . strtolower($this->name);
    }

    /**
     * The seven weekday numbers in the order this preference displays them.
     *
     * @return list<int>
     */
    public function days(): array
    {
        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $days[] = ($this->value + $offset) % 7;
        }

        return $days;
    }

    /**
     * How many blank cells precede the first of the month in a grid that
     * starts on this day.
     *
     * @param int $firstWeekday The month's first day, as `format('w')` gives it.
     */
    public function leadingBlanks(int $firstWeekday): int
    {
        return (($firstWeekday - $this->value) + 7) % 7;
    }

    public static function fromInt(?int $value): self
    {
        return self::tryFrom($value ?? 1) ?? self::Monday;
    }
}
