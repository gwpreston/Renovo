<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Domain\AlertType;
use App\Domain\Entity\NotificationPreferences;
use App\Domain\Entity\Subscription;
use App\Notification\Alert;
use App\Repository\BudgetAlertStateRepository;
use App\Security\Scope;
use App\Service\BudgetService;
use App\Service\SubscriptionService;
use App\Support\Clock;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * Works out what is worth telling one member about today.
 *
 * Two rules shape everything here.
 *
 * **A user is alerted about what is theirs.** The scope decides what they may
 * *see*; ownership decides what they are *told about*. In a SHARED household
 * every member can see every subscription, and notifying all of them about all
 * of it would be the fastest way to get notifications switched off. So an alert
 * goes to the subscription's owner and to its payer when that is somebody else
 * — the two people who can actually act on it.
 *
 * **A lead time fires on "at most this many days left", not "exactly".** With
 * 30/7/1 configured, a charge 29 days out still matches the 30-day reminder and
 * is suppressed by the ledger because the 30-day one has already gone. That
 * sounds like a detail and is the difference between a reminder system and a
 * fragile one: a scheduler that misses Tuesday — a reboot, a full disk, a
 * container restart — would otherwise skip the seven-day warning entirely and
 * never mention it again. Here, Wednesday's run still sends it.
 */
final class AlertScanner
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly BudgetService $budgets,
        private readonly BudgetAlertStateRepository $budgetState,
        private readonly MoneyFormatter $money,
        private readonly Clock $clock,
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * Everything due for this member today, under their lead times.
     *
     * @return list<Alert>
     */
    public function dueAlerts(Scope $scope, NotificationPreferences $preferences): array
    {
        $today = $this->clock->today();
        $alerts = [];

        foreach ($this->subscriptionsFor($scope) as $subscription) {
            $leadDays = $subscription->reminderDaysList() ?? $preferences->leadDays;
            if ($leadDays === []) {
                continue;
            }

            foreach ($this->datedEvents($subscription) as $event) {
                $days = $this->daysUntil($event['date'], $today);
                if ($days < 0) {
                    // The date has passed. The catch-up will move it on; there
                    // is nothing left to warn about.
                    continue;
                }

                $lead = $this->matchingLead($days, $leadDays);
                if ($lead === null) {
                    continue;
                }

                $alerts[] = $this->subscriptionAlert($subscription, $event, $days, $lead);
            }
        }

        return $alerts;
    }

    /**
     * Everything falling inside a window, ignoring lead times — the content of
     * a digest.
     *
     * @return list<Alert>
     */
    public function alertsWithin(Scope $scope, int $days): array
    {
        $today = $this->clock->today();
        $alerts = [];

        foreach ($this->subscriptionsFor($scope) as $subscription) {
            // A subscription silenced individually stays silent in the digest
            // too: "never remind me about this" is not a statement about
            // delivery mode.
            if ($subscription->reminderDaysList() === []) {
                continue;
            }

            foreach ($this->datedEvents($subscription) as $event) {
                $until = $this->daysUntil($event['date'], $today);
                if ($until < 0 || $until > $days) {
                    continue;
                }

                $alerts[] = $this->subscriptionAlert($subscription, $event, $until, $until);
            }
        }

        usort(
            $alerts,
            static fn (Alert $a, Alert $b): int => ($a->dueDate?->getTimestamp() ?? 0)
                <=> ($b->dueDate?->getTimestamp() ?? 0),
        );

        return $alerts;
    }

    /**
     * Re-evaluate every budget this member owns and report the ones that have
     * just crossed their limit.
     *
     * The state machine, and the three cases that matter:
     *
     *  - **Over, and was not.** Alert, and record the breach.
     *  - **Over, and was already.** Silence. This is what "at most one alert
     *    per crossing" means in a model with no calendar period to reset on:
     *    the budget stays over for weeks, and says so once.
     *  - **Under, and was over.** Re-arm silently, so the next crossing is
     *    announced.
     *
     * A projection of null is none of those. It means some of the spend is in a
     * currency with no rate to the budget's, so there is no honest total —
     * treating that as "under" would re-arm a budget that may well still be
     * over, and the next real breach would then look like the first.
     *
     * @return list<Alert>
     */
    public function evaluateBudgets(Scope $scope): array
    {
        $alerts = [];
        $now = $this->clock->now();
        $today = $this->clock->today();

        foreach ($this->budgets->progress($scope) as $progress) {
            $budget = $progress['budget'];
            if ($budget->ownerUserId !== $scope->userId) {
                continue;
            }

            $projected = $progress['projected'];
            if ($projected === null) {
                continue;
            }

            $state = $this->budgetState->findForBudget($scope, $budget->id);
            $wasBreached = $state['is_breached'] ?? false;
            $isBreached = $progress['is_over'];

            if ($isBreached === $wasBreached) {
                // Still the same side of the line. Keep the figure current so
                // the settings page can show it, but say nothing.
                $this->budgetState->save(
                    $scope,
                    $budget->id,
                    $budget->ownerUserId,
                    $isBreached,
                    $projected->amountMinor,
                    null,
                );

                continue;
            }

            $this->budgetState->save(
                $scope,
                $budget->id,
                $budget->ownerUserId,
                $isBreached,
                $projected->amountMinor,
                $isBreached ? $now : null,
            );

            if (!$isBreached) {
                continue;
            }

            $over = $projected->subtract($progress['limit']);

            $alerts[] = new Alert(
                AlertType::BudgetExceeded,
                Alert::SUBJECT_BUDGET,
                $budget->id,
                // The date of the crossing. A budget that drops back under and
                // goes over again later is a new occurrence and is announced
                // again; the same crossing seen twice in one day is not.
                $today->format('Y-m-d'),
                sprintf('Budget "%s" is projected to be exceeded', $budget->name),
                [
                    sprintf(
                        'Projected %s against a limit of %s.',
                        $this->money->format($projected),
                        $this->money->format($progress['limit']),
                    ),
                    sprintf('That is %s over.', $this->money->format($over)),
                ],
                $this->url('/budgets'),
                null,
                7,
            );
        }

        return $alerts;
    }

    /**
     * The subscriptions this member should hear about.
     *
     * @return list<Subscription>
     */
    private function subscriptionsFor(Scope $scope): array
    {
        $mine = [];

        foreach ($this->subscriptions->allForStats($scope) as $subscription) {
            if (!$subscription->isActive) {
                continue;
            }

            if ($subscription->ownerUserId === $scope->userId || $subscription->payerUserId === $scope->userId) {
                $mine[] = $subscription;
            }
        }

        return $mine;
    }

    /**
     * The dated things that can happen to one subscription.
     *
     * A running trial produces a conversion rather than a renewal: they are the
     * same date, and announcing both would be telling somebody twice about one
     * event in two different tones of voice.
     *
     * @return list<array{type: AlertType, date: DateTimeImmutable}>
     */
    private function datedEvents(Subscription $subscription): array
    {
        $events = [];

        if ($subscription->isTrial && $subscription->trialEndDate !== null) {
            $events[] = ['type' => AlertType::TrialConversion, 'date' => $subscription->trialEndDate];
        } elseif ($subscription->nextPaymentDate !== null && $subscription->type->countsTowardsRecurringTotals()) {
            $events[] = ['type' => AlertType::Renewal, 'date' => $subscription->nextPaymentDate];
        }

        // Only worth a separate alert when it is a separate date. Without a
        // notice period the deadline *is* the charge date, which the renewal
        // alert has already covered.
        $deadline = $subscription->cancellationDeadline();
        if ($deadline !== null && $subscription->noticePeriod->isSet()) {
            $events[] = ['type' => AlertType::CancelBy, 'date' => $deadline];
        }

        return $events;
    }

    /**
     * @param array{type: AlertType, date: DateTimeImmutable} $event
     */
    private function subscriptionAlert(Subscription $subscription, array $event, int $days, int $lead): Alert
    {
        $when = match (true) {
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            default => sprintf('in %d days', $days),
        };

        $price = $subscription->isTrial
            ? $subscription->priceAfterConversion()
            : $subscription->price;

        [$title, $lines] = match ($event['type']) {
            AlertType::TrialConversion => [
                sprintf('%s trial ends %s', $subscription->name, $when),
                [
                    sprintf(
                        'It starts charging %s on %s.',
                        $this->money->format($price),
                        $event['date']->format('j M Y'),
                    ),
                ],
            ],
            AlertType::CancelBy => [
                sprintf('Cancel %s by %s', $subscription->name, $event['date']->format('j M Y')),
                [
                    sprintf('That is %s — the last day to give notice and avoid the next charge.', $when),
                    sprintf('It renews at %s.', $this->money->format($price)),
                ],
            ],
            default => [
                sprintf('%s renews %s', $subscription->name, $when),
                [
                    sprintf(
                        '%s is due on %s.',
                        $this->money->format($price),
                        $event['date']->format('j M Y'),
                    ),
                ],
            ],
        };

        return new Alert(
            $event['type'],
            Alert::SUBJECT_SUBSCRIPTION,
            $subscription->id,
            // Charge date plus lead time. The date is what makes a rescheduled
            // payment a genuinely new alert rather than a suppressed duplicate;
            // the lead is what makes 30, 7 and 1 three reminders instead of one.
            $event['date']->format('Y-m-d') . ':' . $lead,
            $title,
            $lines,
            $this->url('/subscriptions/' . $subscription->id . '/money'),
            $event['date'],
            $days <= 1 ? 7 : 5,
        );
    }

    /**
     * The lead time a number of remaining days falls under, or null when the
     * charge is still further out than any of them.
     *
     * @param list<int> $leadDays Sorted descending by the preferences entity.
     */
    private function matchingLead(int $days, array $leadDays): ?int
    {
        $match = null;
        foreach ($leadDays as $lead) {
            if ($days <= $lead && ($match === null || $lead < $match)) {
                $match = $lead;
            }
        }

        return $match;
    }

    private function daysUntil(DateTimeImmutable $date, DateTimeImmutable $today): int
    {
        return (int) $today->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');
    }

    private function url(string $path): ?string
    {
        return $this->appUrl === '' ? null : rtrim($this->appUrl, '/') . $path;
    }
}
