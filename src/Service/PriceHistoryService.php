<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Rounding;
use App\Persistence\Database;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a subscription has cost, does cost, and is about to cost.
 *
 * The model is one immutable row per price, each with the date it takes
 * effect. Nothing overwrites anything:
 *
 *  - the **current** price is the latest row whose date has arrived;
 *  - a **scheduled** change is a row whose date has not;
 *  - the **history** is all of them, which is what the trend view draws.
 *
 * `subscriptions.price_minor` is a denormalisation of the first of those. Every
 * write that changes which row is current updates it in the same transaction,
 * which is the invariant that keeps the list view and the trend view telling
 * the same story. `applyDueChanges()` is the repair for the one case the write
 * path cannot cover — a scheduled row becoming current merely because time
 * passed, with nobody editing anything.
 *
 * @phpstan-type HouseholdChange array{
 *     subscription: Subscription,
 *     change: PriceChange,
 *     from: Money,
 *     to: Money,
 *     kind: 'rise'|'cut'|'unchanged'|'converted',
 *     is_rise: bool,
 *     is_scheduled: bool,
 *     difference_minor: int|null,
 *     percent_tenths: int|null,
 *     annual_minor: int|null
 * }
 */
final class PriceHistoryService
{
    public function __construct(
        private readonly PriceHistoryRepository $history,
        private readonly SubscriptionRepository $subscriptions,
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<PriceChange>
     */
    public function historyFor(Scope $scope, int $subscriptionId): array
    {
        return $this->history->findForSubscription($scope, $subscriptionId);
    }

    /**
     * The price in force on a date — used by the forecast and by the
     * year-over-year reconstruction, both of which ask about the past and the
     * future rather than about now.
     */
    public function priceOn(Scope $scope, int $subscriptionId, DateTimeImmutable $date): ?Money
    {
        return $this->history->findEffectiveOn($scope, $subscriptionId, $date)?->price;
    }

    public function nextScheduledChange(Scope $scope, int $subscriptionId): ?PriceChange
    {
        return $this->history->findNextScheduled($scope, $subscriptionId, $this->clock->today());
    }

    /**
     * Every announced-but-not-yet-applied change in scope, keyed by
     * subscription id.
     *
     * @return array<int, list<PriceChange>>
     */
    public function scheduledChanges(Scope $scope): array
    {
        return $this->history->findScheduledAfter($scope, $this->clock->today());
    }

    /**
     * Every recorded price in scope, keyed by subscription id, oldest first.
     *
     * The bulk form of `historyFor()`, for a caller that has a page full of
     * subscriptions and a question about each of them. One query rather than
     * one per row.
     *
     * @return array<int, list<PriceChange>>
     */
    public function historyBySubscription(Scope $scope): array
    {
        return $this->history->findAllBySubscription($scope);
    }

    /**
     * Every recorded change across the given subscriptions, newest first.
     *
     * The analytics screen's household price history. Each row is one price
     * paired with the one before it in the same subscription's history, in
     * that subscription's own currency — nothing here is converted.
     *
     * Taken over the subscriptions the caller already loaded through the
     * scoping layer, not over every history row in scope: a row's history is
     * listed only when the row itself is visible, so a private subscription's
     * changes reach its payer and nobody else, whatever the history table's
     * own predicate would allow.
     *
     * Three rows are not changes and are left out, or labelled:
     *
     *  - the **first** price has nothing before it to have changed from;
     *  - a **trial conversion** is a trial ending, not a price moving (the
     *    same guard the insight rules keep);
     *  - a **currency change** is listed as `converted`, with no difference
     *    and no percentage, because the price did not change.
     *
     * A scheduled change on a cancelled subscription is left out too: it will
     * never be charged.
     *
     * @param list<Subscription> $subscriptions
     * @return list<HouseholdChange>
     */
    public function householdChanges(Scope $scope, array $subscriptions): array
    {
        $today = $this->clock->today();
        $history = $this->historyBySubscription($scope);

        $rows = [];
        foreach ($subscriptions as $subscription) {
            $previous = null;

            foreach ($history[$subscription->id] ?? [] as $change) {
                $before = $previous;
                $previous = $change;

                if ($before === null || $change->source === PriceChangeSource::TrialConversion) {
                    continue;
                }

                $isScheduled = $change->isScheduled($today);
                if ($isScheduled && $subscription->isCancelled()) {
                    continue;
                }

                $converted = $change->isConversionFrom($before);
                $difference = $converted ? null : $change->differenceFrom($before);
                $cycle = $subscription->billingCycle;

                $rows[] = [
                    'subscription' => $subscription,
                    'change' => $change,
                    'from' => $before->price,
                    'to' => $change->price,
                    'kind' => match (true) {
                        $converted => 'converted',
                        $difference > 0 => 'rise',
                        $difference < 0 => 'cut',
                        default => 'unchanged',
                    },
                    'is_rise' => $change->isRiseFrom($before),
                    'is_scheduled' => $isScheduled,
                    'difference_minor' => $difference,
                    // In tenths of a percent, so "+6.3%" is exact integer
                    // arithmetic until the formatter prints it.
                    'percent_tenths' => $difference !== null && $before->price->amountMinor > 0
                        ? Rounding::multiplyDivide($difference, 1000, $before->price->amountMinor)
                        : null,
                    // At the subscription's own cycle, as the insight rules
                    // state a rise: £2 a month is £24 a year. None for a
                    // one-off or a lifetime purchase, which has no year.
                    'annual_minor' => $difference !== null
                        && $cycle !== null
                        && $subscription->type->countsTowardsRecurringTotals()
                            ? $cycle->annualMinor($difference, $subscription->cycleDays)
                            : null,
                ];
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => $b['change']->effectiveFrom <=> $a['change']->effectiveFrom
                ?: $b['change']->id <=> $a['change']->id,
        );

        return $rows;
    }

    /**
     * Record the price a subscription was created with.
     *
     * Dated from the subscription's start date when it has one, so that a
     * subscription entered today but running since last year does not appear to
     * have sprung into existence at today's price.
     */
    public function recordInitialPrice(
        Scope $scope,
        int $subscriptionId,
        Money $price,
        ?DateTimeImmutable $startDate,
        int $ownerUserId,
    ): void {
        $this->history->append(
            $scope,
            $subscriptionId,
            $price,
            $startDate ?? $this->clock->today(),
            PriceChangeSource::Initial,
            null,
            $ownerUserId,
        );
    }

    /**
     * Record a price that applies from now.
     *
     * Called when somebody edits a subscription's price. The history row and
     * the denormalised column are written together or not at all.
     */
    public function recordCurrentPrice(
        Scope $scope,
        int $subscriptionId,
        Money $price,
        PriceChangeSource $source,
        int $ownerUserId,
        ?string $note = null,
        ?DateTimeImmutable $effectiveFrom = null,
    ): void {
        $effective = $effectiveFrom ?? $this->clock->today();

        $this->db->transactional(function () use (
            $scope,
            $subscriptionId,
            $price,
            $source,
            $ownerUserId,
            $note,
            $effective
        ): void {
            $this->history->append($scope, $subscriptionId, $price, $effective, $source, $note, $ownerUserId);
            $this->subscriptions->setPrice($scope, $subscriptionId, $price);
        });
    }

    /**
     * Schedule a price change for a future date.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function schedule(Scope $scope, int $subscriptionId, array $input): void
    {
        // findForWrite, not find. A scheduled row does not look like a change
        // to the subscription, but it becomes one the moment its date arrives,
        // so it is a write and has to be gated as one. find() is widened for
        // split participants; gating on it would let somebody named on a split
        // reprice a subscription they are only entitled to look at.
        $subscription = $this->subscriptions->findForWrite($scope, $subscriptionId);
        if ($subscription === null) {
            throw new ValidationException(['subscription' => 'error.subscription.not_found']);
        }

        $errors = [];
        $today = $this->clock->today();

        $currency = Currency::normalise($this->str($input, 'currency'));
        if (!Currency::isValidCode($currency)) {
            // Default to the subscription's own currency: a scheduled change is
            // almost always "the same thing costs more", not a re-denomination.
            $currency = $subscription->price->currency;
        }

        $price = null;
        try {
            $price = Money::fromUserInput($this->str($input, 'price'), $currency);
            if ($price->isNegative()) {
                $errors['price'] = 'error.price.negative';
            }
        } catch (InvalidArgumentException) {
            $errors['price'] = 'error.price.invalid';
        }

        $effectiveFrom = $this->date($this->str($input, 'effective_from'));
        if ($effectiveFrom === null) {
            $errors['effective_from'] = 'error.price_change.date_required';
        } elseif ($effectiveFrom <= $today) {
            // A change dated today or earlier is not a schedule, it is an edit.
            // Refusing here keeps the two paths distinguishable rather than
            // letting a "scheduled" row silently become the current price.
            $errors['effective_from'] = 'error.price_change.date_past';
        }

        $note = trim($this->str($input, 'note'));
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'error.note.too_long_255';
        }

        if ($errors !== [] || $price === null || $effectiveFrom === null) {
            throw new ValidationException($errors);
        }

        $this->history->append(
            $scope,
            $subscriptionId,
            $price,
            $effectiveFrom,
            PriceChangeSource::Scheduled,
            $note === '' ? null : $note,
            $subscription->ownerUserId,
        );
    }

    /**
     * Bring the denormalised price on each subscription back in line with its
     * history.
     *
     * This is what makes a scheduled change take effect. It is idempotent and
     * self-healing: it compares before it writes, so on virtually every call it
     * finds nothing and does nothing.
     *
     * It must run *before* payment dates are advanced. A payment rolled forward
     * first would have been rolled at the old price, and the forecast built
     * from it would be wrong by exactly the increase the user scheduled.
     *
     * @return int Number of subscriptions whose price moved.
     */
    public function applyDueChanges(Scope $scope): int
    {
        if (!$scope->canWrite()) {
            // A Viewer's page load must not perform writes. The prices catch up
            // the next time somebody who can write looks at them.
            return 0;
        }

        $divergent = $this->history->findDivergentCurrentPrices($scope, $this->clock->today());
        if ($divergent === []) {
            return 0;
        }

        $applied = 0;
        foreach ($divergent as $row) {
            $this->subscriptions->setPrice(
                $scope,
                $row['subscription_id'],
                Money::of($row['price_minor'], $row['currency']),
            );
            $applied++;
        }

        return $applied;
    }

    /**
     * The trend for one subscription: each recorded price with the step from
     * the one before it, and whether it has happened yet.
     *
     * @return list<array{change: PriceChange, difference_minor: int|null, is_scheduled: bool}>
     */
    public function trendFor(Scope $scope, int $subscriptionId): array
    {
        $today = $this->clock->today();
        $changes = $this->historyFor($scope, $subscriptionId);

        $trend = [];
        $previous = null;
        foreach ($changes as $change) {
            $trend[] = [
                'change' => $change,
                'difference_minor' => $change->differenceFrom($previous),
                'is_scheduled' => $change->isScheduled($today),
            ];
            $previous = $change;
        }

        return $trend;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }
}
