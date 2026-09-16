<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\CalendarGrid;
use App\Domain\Money;
use App\Domain\WeekStart;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The month view of what is coming: renewals and the day a free trial starts
 * charging, laid out on a grid.
 *
 * It asks ForecastService for the charges rather than walking billing dates
 * again. That is the same rule the forecast page and the budgets follow, and
 * it is what makes the calendar agree with them: a scheduled price rise shows
 * the price that will actually be taken, and a trial conversion appears on the
 * day the trial ends, because the one piece of code that knows those rules is
 * the one being asked.
 *
 * The horizon is the forecast's horizon. A calendar that could be paged back
 * into last year would be promising a history this application does not keep —
 * it tracks what is due, not a ledger of what was paid.
 *
 * @phpstan-type CalendarEvent array{
 *     subscription: \App\Domain\Entity\Subscription,
 *     date: DateTimeImmutable,
 *     amount: Money,
 *     reason: string
 * }
 */
final class CalendarService
{
    /** How far forward the calendar may be paged, in months. */
    public const HORIZON_MONTHS = 12;

    public function __construct(
        private readonly ForecastService $forecast,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     month: DateTimeImmutable,
     *     previous: string|null,
     *     next: string|null,
     *     weekdays: list<DateTimeImmutable>,
     *     weeks: list<list<array{date: DateTimeImmutable|null, is_today: bool, events: list<CalendarEvent>}>>,
     *     totals: array<string, int>,
     *     count: int
     * }
     */
    public function month(Scope $scope, DateTimeImmutable $month, WeekStart $weekStart, ?int $forUserId = null): array
    {
        $month = $this->clamp($month);
        $today = $this->clock->today();

        $events = [];
        foreach ($this->forecast->charges($scope, self::HORIZON_MONTHS, $forUserId) as $charge) {
            if ($charge['date']->format('Y-m') === $month->format('Y-m')) {
                $events[$charge['date']->format('Y-m-d')][] = $charge;
            }
        }

        $weeks = [];
        foreach (CalendarGrid::build((int) $month->format('Y'), (int) $month->format('n'), $weekStart) as $week) {
            $row = [];
            foreach ($week as $date) {
                $key = $date?->format('Y-m-d');

                $row[] = [
                    'date' => $date,
                    'is_today' => $key !== null && $key === $today->format('Y-m-d'),
                    'events' => $key === null ? [] : ($events[$key] ?? []),
                ];
            }

            $weeks[] = $row;
        }

        $totals = [];
        $count = 0;
        foreach ($events as $onDay) {
            foreach ($onDay as $event) {
                $currency = $event['amount']->currency;
                $totals[$currency] = ($totals[$currency] ?? 0) + $event['amount']->amountMinor;
                $count++;
            }
        }

        ksort($totals);

        return [
            'month' => $month,
            'previous' => $this->step($month, -1),
            'next' => $this->step($month, 1),
            'weekdays' => CalendarGrid::weekdays($weekStart),
            'weeks' => $weeks,
            'totals' => $totals,
            'count' => $count,
        ];
    }

    /**
     * Parse a `YYYY-MM` from the query string, falling back to this month.
     *
     * Parsed rather than trusted: an out-of-range month is clamped to the
     * horizon instead of returning an empty grid that looks like a month with
     * nothing in it.
     */
    public function resolveMonth(?string $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return $this->firstOfMonth($this->clock->today());
        }

        [$year, $month] = array_map('intval', explode('-', $value));
        if ($month < 1 || $month > 12) {
            return $this->firstOfMonth($this->clock->today());
        }

        return $this->clamp(new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month)));
    }

    private function clamp(DateTimeImmutable $month): DateTimeImmutable
    {
        $month = $this->firstOfMonth($month);
        $earliest = $this->firstOfMonth($this->clock->today());
        $latest = $earliest->modify(sprintf('+%d months', self::HORIZON_MONTHS));

        return match (true) {
            $month < $earliest => $earliest,
            $month > $latest => $latest,
            default => $month,
        };
    }

    /**
     * The neighbouring month, or null where the horizon ends — so the template
     * shows no link rather than one that silently lands back where it started.
     */
    private function step(DateTimeImmutable $month, int $months): ?string
    {
        $target = $this->firstOfMonth($month->modify(sprintf('%+d months', $months)));

        return $this->clamp($target)->format('Y-m') === $target->format('Y-m')
            ? $target->format('Y-m')
            : null;
    }

    private function firstOfMonth(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1)->setTime(0, 0);
    }
}
