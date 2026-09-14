<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The cancel-by dashboard.
 *
 * The date that actually matters to somebody trying to get out of a
 * subscription is not the renewal date — it is the last day they can still give
 * notice and avoid the next charge. For anything with a notice period those are
 * different dates, and the second one is the one that is easy to miss.
 *
 * So this view is sorted by urgency: deadlines already gone, then deadlines
 * this week, then the rest. Something whose deadline has passed is not dropped
 * from the list; it is shown first, because the user has just been committed to
 * another period and should know.
 */
final class CancellationService
{
    /** A deadline inside this many days is shown as urgent. */
    public const URGENT_DAYS = 14;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Every active subscription with a cancellation deadline, most urgent
     * first.
     *
     * @return list<array{
     *     subscription: Subscription,
     *     deadline: DateTimeImmutable,
     *     days_remaining: int,
     *     is_passed: bool,
     *     is_urgent: bool
     * }>
     */
    public function deadlines(Scope $scope, ?int $withinDays = null): array
    {
        $today = $this->clock->today();
        $rows = [];

        foreach ($this->subscriptions->allForStats($scope) as $subscription) {
            if (!$subscription->isActive || !$subscription->type->countsTowardsRecurringTotals()) {
                continue;
            }

            $deadline = $subscription->cancellationDeadline();
            if ($deadline === null) {
                // No notice period: the deadline is simply the renewal date,
                // which the upcoming-renewals view already covers. Listing it
                // here as well would bury the entries that need the warning.
                continue;
            }

            $days = (int) $today->diff($deadline->setTime(0, 0))->format('%r%a');

            if ($withinDays !== null && $days > $withinDays) {
                continue;
            }

            $rows[] = [
                'subscription' => $subscription,
                'deadline' => $deadline,
                'days_remaining' => $days,
                'is_passed' => $days < 0,
                'is_urgent' => $days >= 0 && $days <= self::URGENT_DAYS,
            ];
        }

        // Soonest first, so anything already missed leads — that is the row the
        // user most needs to see, even though nothing can be done about it.
        usort(
            $rows,
            static fn (array $a, array $b): int => $a['days_remaining'] <=> $b['days_remaining']
                ?: strcmp($a['subscription']->name, $b['subscription']->name),
        );

        return $rows;
    }

    /**
     * How many deadlines fall inside the urgent window, for the dashboard
     * badge.
     */
    public function urgentCount(Scope $scope): int
    {
        $count = 0;
        foreach ($this->deadlines($scope) as $row) {
            if ($row['is_urgent']) {
                $count++;
            }
        }

        return $count;
    }
}
