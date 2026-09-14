<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\Entity\PriceChange;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
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
            throw new ValidationException(['subscription' => 'That subscription does not exist.']);
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
                $errors['price'] = 'Enter a price of zero or more.';
            }
        } catch (InvalidArgumentException) {
            $errors['price'] = 'Enter a price, for example 12.99.';
        }

        $effectiveFrom = $this->date($this->str($input, 'effective_from'));
        if ($effectiveFrom === null) {
            $errors['effective_from'] = 'Enter the date the new price starts.';
        } elseif ($effectiveFrom <= $today) {
            // A change dated today or earlier is not a schedule, it is an edit.
            // Refusing here keeps the two paths distinguishable rather than
            // letting a "scheduled" row silently become the current price.
            $errors['effective_from'] = 'Choose a future date. To change the price now, edit the subscription.';
        }

        $note = trim($this->str($input, 'note'));
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'Note must be 255 characters or fewer.';
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
