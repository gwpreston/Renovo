<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BudgetPeriod;
use App\Domain\Currency;
use App\Domain\Entity\Budget;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\MembershipRepository;
use App\Security\Scope;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Budgets and how close they are to being blown.
 *
 * **The trigger is projected spend, not spend so far.** A budget that only
 * reacts once the money has gone is a report, not a budget. What matters is
 * that a scheduled price rise or a trial about to convert will push the member
 * over, and that they hear about it while there is still something they can do.
 *
 * **The projection comes from the forecast, deliberately, rather than from a
 * monthly run-rate.** The two would otherwise disagree: a budget computed from
 * today's prices would miss the very increases that this phase exists to track,
 * and the dashboard would show a budget comfortably under its limit directly
 * above a forecast saying otherwise. Sharing one implementation makes that
 * impossible rather than merely unlikely.
 *
 * Everything a budget measures is *one member's share*. Their own subscriptions
 * in full, their portion of anything split, and nothing belonging to anybody
 * else.
 *
 * Firing alerts is Phase 3. This phase computes the state and shows it.
 *
 * @phpstan-type BudgetProgress array{
 *     budget: Budget,
 *     projected: Money|null,
 *     limit: Money,
 *     percent: int|null,
 *     is_over: bool,
 *     is_warning: bool,
 *     remaining: Money|null,
 *     unconvertible: list<string>
 * }
 */
final class BudgetService
{
    private const MAX_WARN_THRESHOLD = 100;

    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly CategoryRepository $categories,
        private readonly MembershipRepository $memberships,
        private readonly ForecastService $forecast,
        private readonly ExchangeRateService $rates,
    ) {
    }

    /**
     * @return list<Budget>
     */
    public function all(Scope $scope, bool $activeOnly = true): array
    {
        return $this->budgets->findAll($scope, $activeOnly);
    }

    public function find(Scope $scope, int $id): ?Budget
    {
        return $this->budgets->find($scope, $id);
    }

    /**
     * Every budget in scope with its projected position.
     *
     * @return list<BudgetProgress>
     */
    public function progress(Scope $scope): array
    {
        $budgets = $this->all($scope);
        if ($budgets === []) {
            return [];
        }

        // The forecast is walked once per member and horizon rather than once
        // per budget: a member with an overall budget and five category ones
        // would otherwise walk the same twelve months six times.
        $chargeCache = [];
        $progress = [];

        foreach ($budgets as $budget) {
            $key = $budget->ownerUserId . ':' . $budget->period->months();
            $chargeCache[$key] ??= $this->forecast->charges(
                $scope,
                $budget->period->months(),
                $budget->ownerUserId,
            );

            $progress[] = $this->progressFor($budget, $chargeCache[$key]);
        }

        return $progress;
    }

    /**
     * One budget's position.
     *
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money,
     *                   reason: string}> $charges
     * @return BudgetProgress
     */
    public function progressFor(Budget $budget, array $charges): array
    {
        $byCurrency = [];
        foreach ($charges as $charge) {
            if ($budget->categoryId !== null && $charge['subscription']->categoryId !== $budget->categoryId) {
                continue;
            }

            $currency = $charge['amount']->currency;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $charge['amount']->amountMinor;
        }

        $target = $budget->amount->currency;
        $unconvertible = [];
        foreach (array_keys($byCurrency) as $currency) {
            if ($this->rates->rateFor((string) $currency, $target) === null) {
                $unconvertible[] = (string) $currency;
            }
        }

        if ($unconvertible !== []) {
            // Some of the spend cannot be expressed in the budget's currency,
            // so there is no honest total. Reporting the convertible part as
            // though it were the whole would show a budget under its limit
            // when it may be well over — the one failure mode worth refusing
            // outright.
            sort($unconvertible);

            return [
                'budget' => $budget,
                'projected' => null,
                'limit' => $budget->amount,
                'percent' => null,
                'is_over' => false,
                'is_warning' => false,
                'remaining' => null,
                'unconvertible' => $unconvertible,
            ];
        }

        $projectedMinor = $this->rates->combine($byCurrency, $target) ?? 0;
        $projected = Money::of($projectedMinor, $target);
        $limitMinor = $budget->amount->amountMinor;

        $percent = $limitMinor > 0
            ? Rounding::multiplyDivide($projectedMinor, 100, $limitMinor)
            : null;

        $threshold = $budget->warnThresholdPercent;

        return [
            'budget' => $budget,
            'projected' => $projected,
            'limit' => $budget->amount,
            'percent' => $percent,
            'is_over' => $projectedMinor > $limitMinor,
            // A warning is only a warning while it is not yet a breach —
            // showing both at once would be noise.
            'is_warning' => $threshold !== null
                && $percent !== null
                && $percent >= $threshold
                && $projectedMinor <= $limitMinor,
            'remaining' => Money::of($limitMinor - $projectedMinor, $target),
            'unconvertible' => [],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(Scope $scope, array $input): int
    {
        return $this->budgets->create($scope, $this->validate($scope, $input));
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(Scope $scope, int $id, array $input): void
    {
        $this->budgets->update($scope, $id, $this->validate($scope, $input));
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->budgets->delete($scope, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function validate(Scope $scope, array $input): array
    {
        $errors = [];

        $name = trim($this->str($input, 'name'));
        if ($name === '') {
            $errors['name'] = 'error.budget.name_required';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'error.name.too_long_100';
        }

        $currency = Currency::normalise($this->str($input, 'currency'));
        if (!Currency::isValidCode($currency)) {
            $errors['currency'] = 'error.currency.required';
            $currency = 'GBP';
        }

        $amount = null;
        try {
            $amount = Money::fromUserInput($this->str($input, 'amount'), $currency);
            if ($amount->isNegative()) {
                $errors['amount'] = 'error.amount.negative';
            }
        } catch (InvalidArgumentException) {
            $errors['amount'] = 'error.amount.invalid';
        }

        $period = BudgetPeriod::tryFromString($this->str($input, 'period'));
        if ($period === null) {
            $errors['period'] = 'error.budget.period';
        }

        $categoryId = $this->positiveInt($input['category_id'] ?? null);
        if ($categoryId !== null && $this->categories->find($scope, $categoryId) === null) {
            $errors['category_id'] = 'error.category.not_found';
        }

        $thresholdRaw = trim($this->str($input, 'warn_threshold_percent'));
        $threshold = $thresholdRaw === '' ? null : (int) $thresholdRaw;
        if ($threshold !== null && ($threshold < 1 || $threshold > self::MAX_WARN_THRESHOLD)) {
            $errors['warn_threshold_percent'] = 'error.budget.threshold_range';
        }

        $ownerUserId = $this->resolveOwner($scope, $input, $errors);

        if ($errors !== [] || $amount === null || $period === null) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name,
            'category_id' => $categoryId,
            'period' => $period->value,
            'amount_minor' => $amount->amountMinor,
            'currency' => $currency,
            'warn_threshold_percent' => $threshold,
            'is_active' => ($input['is_active'] ?? '1') !== '0',
            'owner_user_id' => $ownerUserId,
        ];
    }

    /**
     * Whose budget this is.
     *
     * In ISOLATED mode it can only ever be your own — a budget you could not
     * then see would be worse than useless. In SHARED mode an Owner/Admin may
     * set one up on behalf of another member, which is how a household sets
     * expectations for somebody who has not got round to it.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function resolveOwner(Scope $scope, array $input, array &$errors): int
    {
        if ($scope->restrictsWritesToOwner()) {
            return $scope->userId;
        }

        $requested = $this->positiveInt($input['owner_user_id'] ?? null);
        if ($requested === null || $requested === $scope->userId) {
            return $scope->userId;
        }

        $memberIds = $scope->hasHousehold()
            ? array_map(
                static fn (array $member): int => $member['id'],
                $this->memberships->findMembersOfHousehold((int) $scope->householdId),
            )
            : [];

        if (!in_array($requested, $memberIds, true)) {
            $errors['owner_user_id'] = 'error.member.not_in_household';

            return $scope->userId;
        }

        return $requested;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_scalar($value) || (string) $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
