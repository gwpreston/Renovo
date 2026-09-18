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
 * The rail beside the grid is drawn from the same set of charges. Asking the
 * forecast a second question would be asking it to agree with itself, and a
 * second query would be a second place for scoping to be got wrong; every
 * figure in the rail is therefore a selection over the charges already
 * fetched, made here rather than in the template.
 *
 * @phpstan-type CalendarEvent array{
 *     subscription: \App\Domain\Entity\Subscription,
 *     date: DateTimeImmutable,
 *     amount: Money,
 *     reason: string
 * }
 * @phpstan-type CancellationDeadline array{
 *     subscription: \App\Domain\Entity\Subscription,
 *     date: DateTimeImmutable,
 *     amount: Money,
 *     charge_date: DateTimeImmutable
 * }
 */
final class CalendarService
{
    /** How far forward the calendar may be paged, in months. */
    public const HORIZON_MONTHS = 12;

    /**
     * How many charges the "next up" rail previews.
     *
     * Short on purpose: it answers "what is about to leave the account", and a
     * list long enough to need scrolling is the forecast page, which already
     * exists.
     */
    public const UPCOMING_PREVIEW = 6;

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
     *     count: int,
     *     trials: list<CalendarEvent>,
     *     trial_totals: array<string, int>,
     *     deadlines: list<CancellationDeadline>,
     *     upcoming: list<CalendarEvent>
     * }
     */
    public function month(Scope $scope, DateTimeImmutable $month, WeekStart $weekStart, ?int $forUserId = null): array
    {
        $month = $this->clamp($month);
        $today = $this->clock->today();

        $charges = $this->forecast->charges($scope, self::HORIZON_MONTHS, $forUserId);

        $events = [];
        foreach ($charges as $charge) {
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

        $trials = $this->trialsIn($events);

        $trialTotals = [];
        foreach ($trials as $trial) {
            $currency = $trial['amount']->currency;
            $trialTotals[$currency] = ($trialTotals[$currency] ?? 0) + $trial['amount']->amountMinor;
        }

        ksort($trialTotals);

        return [
            'month' => $month,
            'previous' => $this->step($month, -1),
            'next' => $this->step($month, 1),
            'weekdays' => CalendarGrid::weekdays($weekStart),
            'weeks' => $weeks,
            'totals' => $totals,
            'count' => $count,
            'trials' => $trials,
            'trial_totals' => $trialTotals,
            'deadlines' => $this->deadlinesIn($charges, $month, $today),
            'upcoming' => array_slice($charges, 0, self::UPCOMING_PREVIEW),
        ];
    }

    /**
     * The month's trial conversions, in date order.
     *
     * A conversion is the one event on this grid that is not a subscription
     * carrying on as it was — it is the day something free starts costing
     * money — so it is worth naming separately from the renewals around it.
     *
     * @param array<string, list<CalendarEvent>> $events Keyed by `Y-m-d`.
     * @return list<CalendarEvent>
     */
    private function trialsIn(array $events): array
    {
        ksort($events);

        $trials = [];
        foreach ($events as $onDay) {
            foreach ($onDay as $event) {
                if ($event['reason'] === 'trial_conversion') {
                    $trials[] = $event;
                }
            }
        }

        return $trials;
    }

    /**
     * Cancellation deadlines falling inside the displayed month.
     *
     * A deadline is not a charge and does not appear in the forecast, so it is
     * derived here from the subscriptions the forecast already returned —
     * which is what keeps it inside the same scoped, split-filtered set as
     * everything else on the page rather than a second query with its own
     * ideas about who may see what.
     *
     * Only the *first* charge for a subscription is considered, because
     * `cancellationDeadline()` is measured from the next charge: a deadline
     * computed against a renewal six months out would be a date nobody has to
     * act on yet, presented as though they did.
     *
     * A deadline already past is dropped rather than shown in the past tense.
     * The whole value of the figure is that something can still be done about
     * it, and the notice period may be long enough to put the deadline in a
     * month the grid can no longer be paged back to.
     *
     * @param list<CalendarEvent> $charges In date order.
     * @return list<CancellationDeadline>
     */
    private function deadlinesIn(array $charges, DateTimeImmutable $month, DateTimeImmutable $today): array
    {
        $deadlines = [];
        $seen = [];

        foreach ($charges as $charge) {
            $subscription = $charge['subscription'];
            if (isset($seen[$subscription->id])) {
                continue;
            }

            $seen[$subscription->id] = true;

            $deadline = $subscription->cancellationDeadline();
            if ($deadline === null || $deadline < $today) {
                continue;
            }

            if ($deadline->format('Y-m') !== $month->format('Y-m')) {
                continue;
            }

            $deadlines[] = [
                'subscription' => $subscription,
                'date' => $deadline,
                'amount' => $charge['amount'],
                'charge_date' => $charge['date'],
            ];
        }

        usort(
            $deadlines,
            static fn (array $a, array $b): int => $a['date'] <=> $b['date']
                ?: $a['subscription']->id <=> $b['subscription']->id,
        );

        return $deadlines;
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
