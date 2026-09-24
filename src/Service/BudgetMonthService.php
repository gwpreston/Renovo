<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BudgetPeriod;
use App\Domain\Entity\Budget;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Budgets read against the calendar month: what has been charged so far, and
 * where the month will end.
 *
 * `BudgetService::progress()` projects a rolling horizon — today to the same
 * day next month — because an alert is about the next charge wherever it
 * falls. A card headed with the month's name needs the month itself: the
 * charges from the first to yesterday, which the reconstruction supplies, and
 * the rest of the month, which the forecast does. Read that way the two bars on
 * a budget — charged and projected — are on one scale, and the projected one
 * is the charged one plus what is still to come.
 *
 * Every figure goes through `BudgetService::progressFor()`, so the currency
 * rules, the warning threshold and the refusal to total an unconvertible
 * currency are the budget screen's own. A member budget counts that member's
 * share, and past charges take today's split (see `SpendHistoryService`).
 *
 * @phpstan-import-type BudgetProgress from BudgetService
 * @phpstan-type Charge array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}
 * @phpstan-type MonthBudget array{
 *     budget: Budget,
 *     limit: Money,
 *     charged: Money|null,
 *     projected: Money|null,
 *     committed: Money|null,
 *     charged_percent: int|null,
 *     projected_percent: int|null,
 *     is_over: bool,
 *     over_if_trials_convert: bool,
 *     is_warning: bool,
 *     remaining: Money|null,
 *     unconvertible: list<string>
 * }
 */
final class BudgetMonthService
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly SpendHistoryService $history,
        private readonly ForecastService $forecast,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The viewer's monthly budgets, read against this month.
     *
     * Only budgets this scope can measure honestly are included — the budget
     * screen explains the others. The overall ones lead, the household's before
     * a member's, then the per-category ones, each group oldest first so the
     * order holds still between visits.
     *
     * @return list<MonthBudget>
     */
    public function thisMonth(Scope $scope, int $limit): array
    {
        $monthly = array_values(array_filter(
            $this->budgets->all($scope),
            fn (Budget $budget): bool => $budget->period === BudgetPeriod::Monthly
                && $this->budgets->isMeasurableBy($scope, $budget),
        ));

        usort($monthly, static function (Budget $a, Budget $b): int {
            return ($a->isOverall() ? 0 : 1) <=> ($b->isOverall() ? 0 : 1)
                ?: ($a->isHousehold() ? 0 : 1) <=> ($b->isHousehold() ? 0 : 1)
                ?: $a->id <=> $b->id;
        });

        $rows = [];
        $cache = [];
        foreach (array_slice($monthly, 0, $limit) as $budget) {
            $key = (string) ($budget->subjectUserId ?? 'household');
            $cache[$key] ??= $this->monthCharges($scope, $budget->subjectUserId);

            $rows[] = $this->read($budget, $cache[$key]['past'], $cache[$key]['ahead']);
        }

        return $rows;
    }

    /**
     * The household's overall budget for a period, if there is one it can be
     * measured by.
     *
     * The budget a household-wide figure is measured against: the chart's
     * budget line, the month's marker, the spend tile's percentage, the pace.
     * A member's own budget measures their share and would make any of those a
     * wrong percentage of the household total, so it is never the answer here.
     * In ISOLATED mode no household budget can be measured, so there is none.
     */
    public function householdOverall(Scope $scope, BudgetPeriod $period): ?Budget
    {
        $candidates = array_values(array_filter(
            $this->budgets->all($scope),
            fn (Budget $budget): bool => $budget->period === $period
                && $budget->isOverall()
                && $budget->isHousehold()
                && $this->budgets->isMeasurableBy($scope, $budget),
        ));

        usort($candidates, static fn (Budget $a, Budget $b): int => $a->id <=> $b->id);

        return $candidates[0] ?? null;
    }

    /**
     * This month's charges for one subject: before today, and today to the end.
     *
     * @return array{past: list<Charge>, ahead: list<Charge>}
     */
    private function monthCharges(Scope $scope, ?int $subjectUserId): array
    {
        $today = $this->clock->today();
        $firstOfMonth = $today->modify('first day of this month');
        $endOfMonth = $today->modify('last day of this month');

        $past = $this->history->charges(
            $scope,
            $firstOfMonth->modify('-1 day'),
            $today->modify('-1 day'),
            $subjectUserId,
        )['charges'];

        $ahead = array_values(array_filter(
            $this->forecast->charges($scope, 1, $subjectUserId),
            static fn (array $charge): bool => $charge['date'] <= $endOfMonth,
        ));

        return ['past' => $past, 'ahead' => $ahead];
    }

    /**
     * @param list<Charge> $past
     * @param list<Charge> $ahead
     * @return MonthBudget
     */
    private function read(Budget $budget, array $past, array $ahead): array
    {
        $charged = $this->budgets->progressFor($budget, $past);
        $projected = $this->budgets->progressFor($budget, array_merge($past, $ahead));
        // The month with the running trials taken out: what is committed
        // whatever happens to them. The distance between this and the
        // projection is what the trials would add if nobody cancels them.
        $committed = $this->budgets->progressFor($budget, array_values(array_filter(
            array_merge($past, $ahead),
            static fn (array $charge): bool => !$charge['subscription']->isTrial,
        )));

        $limitMinor = $budget->amount->amountMinor;
        $unconvertible = array_values(array_unique(array_merge(
            $charged['unconvertible'],
            $projected['unconvertible'],
        )));
        sort($unconvertible);

        $isOverCommitted = $committed['projected'] !== null
            && $committed['projected']->amountMinor > $limitMinor;

        return [
            'budget' => $budget,
            'limit' => $budget->amount,
            'charged' => $charged['projected'],
            'projected' => $projected['projected'],
            'committed' => $committed['projected'],
            'charged_percent' => $this->percent($charged['projected'], $limitMinor),
            'projected_percent' => $projected['percent'],
            'is_over' => $isOverCommitted,
            // Under the limit on what is committed, over it once the trials
            // convert: the one warning a member can still act on by cancelling.
            'over_if_trials_convert' => !$isOverCommitted && $projected['is_over'],
            'is_warning' => $projected['is_warning'],
            'remaining' => $projected['remaining'],
            'unconvertible' => $unconvertible,
        ];
    }

    private function percent(?Money $figure, int $limitMinor): ?int
    {
        if ($figure === null || $limitMinor <= 0) {
            return null;
        }

        return Rounding::multiplyDivide($figure->amountMinor, 100, $limitMinor);
    }
}
