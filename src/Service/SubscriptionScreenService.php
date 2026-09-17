<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Permission;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Everything on the my-subscriptions screen that is not the list itself.
 *
 * The counterpart to DashboardService, and built on the same principle: not one
 * figure here is computed for this screen. The strip is the statistics the
 * Statistics page reports, the expiring cards are the near-window renewals the
 * dashboard counts and the cancel-by deadlines that view is made of, the trials
 * are the trials the reminder run watches, and the widget is the category
 * distribution drawn against a stated total. Two screens showing different
 * answers to "what does this cost" is the failure this arrangement forbids.
 *
 * **One near window.** Fourteen days, referenced from CancellationService
 * rather than re-declared, which is what makes "the same window the dashboard
 * uses" a fact about the code instead of a claim in a comment.
 *
 * @phpstan-type ExpiringRow array{
 *     subscription: Subscription,
 *     date: DateTimeImmutable,
 *     days: int,
 *     is_passed: bool,
 *     is_urgent: bool,
 *     can_act: bool
 * }
 * @phpstan-type TrialRow array{
 *     subscription: Subscription,
 *     converts_on: DateTimeImmutable,
 *     days_left: int,
 *     is_urgent: bool,
 *     converts_to: Money,
 *     can_act: bool
 * }
 */
final class SubscriptionScreenService
{
    /** The near window, in days: the one the cancel-by view calls urgent. */
    public const NEAR_WINDOW_DAYS = CancellationService::URGENT_DAYS;

    public function __construct(
        private readonly StatsService $stats,
        private readonly SubscriptionService $subscriptions,
        private readonly CancellationService $cancellations,
        private readonly CategoryBreakdownService $breakdown,
        private readonly PermissionService $permissions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The strip, the two expiring sections, the trials and the widget.
     *
     * Computed for a full page load and not for an htmx one: filtering, sorting
     * and paging replace the table, and recomputing the household's statistics
     * to swap twenty-five rows would be a great deal of work to arrive at the
     * same numbers that are already on the screen.
     *
     * @return array{
     *     strip: array<string, mixed>,
     *     expiring: array{days: int, renewing: list<ExpiringRow>, cancel_by: list<ExpiringRow>},
     *     trials: array{rows: list<TrialRow>, totals: list<array{currency: string, total_minor: int, count: int}>},
     *     category_spending: array<string, mixed>
     * }
     */
    public function overview(Scope $scope): array
    {
        // First: it brings due price changes, ended trials and overdue payment
        // dates up to date, so nothing below is read from a figure the next
        // page load would correct.
        $stats = $this->stats->dashboard($scope);

        $soon = $this->subscriptions->upcoming($scope, self::NEAR_WINDOW_DAYS);
        $trials = $this->subscriptions->trialsBeforeConversion($scope);

        return [
            'strip' => $this->strip($stats, $soon),
            'expiring' => $this->expiring($scope, $soon),
            'trials' => $this->trials($scope, $trials),
            // Not `categories`: the page already has a list of them for its
            // filter, and a screen where one name means two things is a screen
            // waiting to show the wrong one.
            'category_spending' => $this->categories($stats),
        ];
    }

    /**
     * The three figures across the top.
     *
     * The yearly one keeps the per-currency rule the rest of the application
     * follows — subtotals, with a combined figure only when every currency in
     * play converts — so it is shaped exactly like the dashboard's spend tiles
     * and rendered by the same partial. The design's single clean number is the
     * common case rather than the only one; a headline that silently dropped a
     * currency would be wrong rather than tidy.
     *
     * @param array<string, mixed> $stats
     * @param list<Subscription>   $soon
     * @return array<string, mixed>
     */
    private function strip(array $stats, array $soon): array
    {
        /** @var list<array{currency: string, monthly_minor: int, yearly_minor: int, count: int}> $recurring */
        $recurring = $stats['recurring'];

        return [
            'active_count' => $stats['active_count'],
            'yearly' => [
                'totals' => array_map(
                    static fn (array $row): array => [
                        'currency' => $row['currency'],
                        'amount_minor' => $row['yearly_minor'],
                    ],
                    $recurring,
                ),
                'combined' => $stats['combined_yearly'],
            ],
            'renewals' => [
                'count' => count($soon),
                'days' => self::NEAR_WINDOW_DAYS,
                'total' => $this->stats->sumByCurrency($soon),
            ],
        ];
    }

    /**
     * The two deadlines this application actually distinguishes.
     *
     * *Renewing soon* is a charge inside the near window — the same list the
     * strip counted, passed in rather than queried again. *Cancel by* is the
     * last day notice can be given, and it exists only for a subscription with
     * a notice period: without one the deadline **is** the renewal date, and a
     * second card saying so again would be noise. That filtering is not done
     * here — `CancellationService` has always skipped a subscription without a
     * notice period, for that reason.
     *
     * Deadlines already missed are kept and shown first. Nothing can be done
     * about them, but the user has just been committed to another period and is
     * the last person who should have to work that out for themselves.
     *
     * @param list<Subscription> $soon
     * @return array{days: int, renewing: list<ExpiringRow>, cancel_by: list<ExpiringRow>}
     */
    private function expiring(Scope $scope, array $soon): array
    {
        $today = $this->clock->today();
        $mayUpdate = $this->permissions->allows($scope, Permission::UpdateSubscription);

        $renewing = [];
        foreach ($soon as $subscription) {
            $date = $subscription->nextChargeDate();
            if ($date === null) {
                continue;
            }

            $renewing[] = [
                'subscription' => $subscription,
                'date' => $date,
                // Counted to the date the card shows, the way the cancel-by
                // view counts to its deadline. Asking the payment date instead
                // would answer for a different day than the one printed beside
                // it the moment the two are not the same — which for a trial
                // they are not.
                'days' => (int) $today->diff($date->setTime(0, 0))->format('%r%a'),
                'is_passed' => false,
                // Every row in this card is inside the near window already, so
                // singling one out would mark the whole list. The card is the
                // warning; the dates inside it are a list.
                'is_urgent' => false,
                'can_act' => $this->canAct($scope, $subscription, $mayUpdate),
            ];
        }

        $cancelBy = [];
        foreach ($this->cancellations->deadlines($scope, self::NEAR_WINDOW_DAYS) as $row) {
            $cancelBy[] = [
                'subscription' => $row['subscription'],
                'date' => $row['deadline'],
                'days' => $row['days_remaining'],
                'is_passed' => $row['is_passed'],
                // The cancel-by view's own judgement, carried over rather than
                // re-made: one row must not be urgent on this screen and
                // ordinary on that one.
                'is_urgent' => $row['is_urgent'],
                'can_act' => $this->canAct($scope, $row['subscription'], $mayUpdate),
            ];
        }

        return [
            'days' => self::NEAR_WINDOW_DAYS,
            'renewing' => $renewing,
            'cancel_by' => $cancelBy,
        ];
    }

    /**
     * Trials that have not converted yet.
     *
     * The trial's last day is the day the first charge falls, so the countdown
     * runs to that day and the amount shown is what it converts to — the price
     * the subscription will have, not the zero it has now and not a placeholder.
     * A trial is the subscription it will become rather than a separate record,
     * which is why the action on this card is the ordinary one: pausing it here
     * pauses the subscription, because they are the same row.
     *
     * @param list<Subscription> $trials
     * @return array{rows: list<TrialRow>, totals: list<array{currency: string, total_minor: int, count: int}>}
     */
    private function trials(Scope $scope, array $trials): array
    {
        $today = $this->clock->today();
        $mayUpdate = $this->permissions->allows($scope, Permission::UpdateSubscription);

        $rows = [];
        foreach ($trials as $trial) {
            if ($trial->trialEndDate === null) {
                continue;
            }

            $daysLeft = $trial->daysUntilTrialEnds($today) ?? 0;

            $rows[] = [
                'subscription' => $trial,
                'converts_on' => $trial->trialEndDate,
                'days_left' => $daysLeft,
                // Trials are not bounded by the near window — this section
                // lists every one of them — so a conversion falling inside it
                // is the row worth picking out, by the same fourteen days
                // everything else on this screen calls near.
                'is_urgent' => $daysLeft <= self::NEAR_WINDOW_DAYS,
                'converts_to' => $trial->priceAfterConversion(),
                'can_act' => $this->canAct($scope, $trial, $mayUpdate),
            ];
        }

        return [
            'rows' => $rows,
            // What the trials will cost once they convert, which is the only
            // figure anybody is interested in about a set of free things.
            'totals' => $this->stats->sumConvertedPrices($trials),
        ];
    }

    /**
     * Where the recurring spend goes, as proportion bars.
     *
     * The rows, the ordering and the denominator are all
     * `CategoryBreakdownService`'s — the same breakdown the analytics screen
     * draws as a donut. Two pictures of one set of figures, which is the only
     * arrangement in which they cannot disagree.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function categories(array $stats): array
    {
        return $this->breakdown->fromStats($stats);
    }

    /**
     * Whether this member could act on this subscription at all.
     *
     * Two questions, both of which have to be yes: may their role change a
     * subscription, and is this particular row one they may change? The second
     * is not implied by seeing it — under ISOLATED isolation a member can see a
     * shared cost they contribute to without owning it — so a card that offered
     * them the button would be offering a refusal. The refusal itself still
     * happens in the repository; this only decides what to draw.
     */
    private function canAct(Scope $scope, Subscription $subscription, bool $mayUpdate): bool
    {
        return $mayUpdate && $scope->mayWriteRow($subscription->householdId, $subscription->ownerUserId);
    }
}
