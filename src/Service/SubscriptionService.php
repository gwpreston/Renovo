<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\Currency;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\PriceChangeSource;
use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionType;
use App\Domain\Visibility;
use App\Persistence\Database;
use App\Repository\CategoryRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * All subscription behaviour, in one place.
 *
 * Web controllers call these methods, and so will the API when it arrives, so
 * validation rules and the auto-advance policy cannot drift apart between the
 * two. Everything here takes a Scope and hands it to the repository — there is
 * no path from a service to the database that skips it.
 */
final class SubscriptionService
{
    private const MAX_ADVANCE_ITERATIONS = 500;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly CategoryRepository $categories,
        private readonly PaymentMethodRepository $paymentMethods,
        private readonly TagRepository $tags,
        private readonly LogoFetcher $logoFetcher,
        private readonly MembershipRepository $memberships,
        private readonly PriceHistoryService $priceHistory,
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<Subscription>
     */
    public function list(Scope $scope, SubscriptionFilter $filter): array
    {
        return $this->subscriptions->findForList($scope, $filter);
    }

    public function count(Scope $scope, SubscriptionFilter $filter): int
    {
        return $this->subscriptions->countForList($scope, $filter);
    }

    public function find(Scope $scope, int $id): ?Subscription
    {
        return $this->subscriptions->find($scope, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @param bool $restoring True only for a backup being put back. Two things
     *        differ, both because the row is being returned rather than made:
     *        a private row may belong to the member it belonged to, not only to
     *        the person restoring it, and `cancelled_at` — read-only everywhere
     *        else — is written with the row, so a cancelled subscription comes
     *        back cancelled in the same statement that creates it.
     * @throws ValidationException
     */
    public function create(Scope $scope, array $input, bool $restoring = false): int
    {
        [$data, $tagIds] = $this->validate($scope, $input, restoring: $restoring);

        // The subscription and the first row of its price history are one
        // fact, so they are written as one. A subscription with no history
        // would have no current price to resolve and no trend to draw.
        return $this->db->transactional(function () use ($scope, $data, $tagIds): int {
            $id = $this->subscriptions->create($scope, $data, $tagIds);

            $this->priceHistory->recordInitialPrice(
                $scope,
                $id,
                Money::of((int) $data['price_minor'], (string) $data['currency']),
                $this->date((string) ($data['start_date'] ?? '')),
                (int) $data['owner_user_id'],
            );

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(Scope $scope, int $id, array $input): void
    {
        $existing = $this->subscriptions->find($scope, $id);
        [$data, $tagIds] = $this->validate($scope, $input, $id);

        $price = Money::of((int) $data['price_minor'], (string) $data['currency']);

        $this->db->transactional(function () use ($scope, $id, $data, $tagIds, $existing, $price): void {
            $this->subscriptions->update($scope, $id, $data, $tagIds);

            // A price that has actually moved becomes a new history row rather
            // than overwriting the old one. Editing anything else about a
            // subscription leaves its history alone.
            if ($existing !== null && !$this->isSamePrice($existing->price, $price)) {
                $this->priceHistory->recordCurrentPrice(
                    $scope,
                    $id,
                    $price,
                    $existing->price->currency === $price->currency
                        ? PriceChangeSource::Manual
                        : PriceChangeSource::CurrencyChange,
                    (int) $data['owner_user_id'],
                );
            }
        });
    }

    /**
     * Check an input without writing anything.
     *
     * The importer's preview needs to tell a user which of four hundred rows
     * will fail *before* any of them is written. It could not do that by
     * reimplementing the rules — they would drift within a release — and it must
     * not do it by attempting the writes and rolling back, which on MySQL would
     * not roll back the auto-increment and on either engine would fire the
     * price-history writes. So the same `validate()` the real write uses is
     * offered here with its result caught.
     *
     * @param array<string, mixed> $input
     * @return array<string, ValidationError> Field errors; empty when the row
     *         is valid. Keys, not sentences — the preview renders them.
     */
    public function validationErrors(Scope $scope, array $input): array
    {
        try {
            // Nothing is written on a dry run. `validate()` creates any tag it
            // does not recognise and fetches a logo for any website it is
            // given; a preview that invented forty tags — or made four hundred
            // outbound requests — for rows the user then decided not to import
            // would be a page load with consequences.
            $this->validate($scope, $input, commits: false);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    }

    /**
     * Validate the trial fields.
     *
     * A trial is only meaningful on something that recurs: a one-off purchase
     * with a free trial is not a thing, and allowing it would put a conversion
     * date on a row the conversion logic never looks at.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validateTrial(array $input, SubscriptionType $type): array
    {
        $none = [
            'is_trial' => false,
            'trial_end_date' => null,
            'converts_to_price_minor' => null,
            'converts_to_billing_cycle' => null,
            'converts_to_cycle_days' => null,
        ];

        if (($input['is_trial'] ?? '0') !== '1' || !$type->hasBillingCycle()) {
            return [$none, []];
        }

        $errors = [];

        $endDate = $this->date($this->str($input, 'trial_end_date'));
        if ($endDate === null) {
            $errors['trial_end_date'] = 'error.trial.end_date_required';
        }

        // The converts-to price is optional: plenty of trials convert to
        // whatever the subscription already says it costs.
        $convertsToMinor = null;
        $convertsToRaw = trim($this->str($input, 'converts_to_price'));
        if ($convertsToRaw !== '') {
            $currency = Currency::normalise($this->str($input, 'currency'));
            try {
                $converts = Money::fromUserInput($convertsToRaw, Currency::isValidCode($currency) ? $currency : 'GBP');
                if ($converts->isNegative()) {
                    $errors['converts_to_price'] = 'error.price.negative';
                } else {
                    $convertsToMinor = $converts->amountMinor;
                }
            } catch (InvalidArgumentException) {
                $errors['converts_to_price'] = 'error.trial.converts_to_invalid';
            }
        }

        $convertsToCycle = BillingCycle::tryFromString($this->str($input, 'converts_to_billing_cycle'));
        $convertsToCycleDays = null;
        if ($convertsToCycle !== null && $convertsToCycle->requiresCycleDays()) {
            $convertsToCycleDays = (int) $this->str($input, 'converts_to_cycle_days');
            if ($convertsToCycleDays < 1 || $convertsToCycleDays > 3650) {
                $errors['converts_to_cycle_days'] = 'error.cycle_days.range';
            }
        }

        if ($errors !== []) {
            return [$none, $errors];
        }

        return [[
            'is_trial' => true,
            'trial_end_date' => $endDate?->format('Y-m-d'),
            'converts_to_price_minor' => $convertsToMinor,
            'converts_to_billing_cycle' => $convertsToCycle?->value,
            'converts_to_cycle_days' => $convertsToCycleDays,
        ], []];
    }

    /**
     * The per-subscription reminder override.
     *
     * Three states, all of them meaningful: the field absent means "leave it
     * as it is", the field present but empty means "use my preference", and
     * the word "none" means "never remind me about this one". Collapsing the
     * last two would make it impossible to silence a single subscription
     * without silencing everything.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function reminderDays(array $input, array &$errors): ?string
    {
        if (!array_key_exists('reminder_days', $input)) {
            return null;
        }

        $raw = strtolower(trim($this->str($input, 'reminder_days')));

        if ($raw === '') {
            return null;
        }

        if ($raw === 'none' || $raw === 'never') {
            return '';
        }

        $days = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (!ctype_digit($part) || (int) $part > 365) {
                $errors['reminder_days'] = 'error.reminder_days.invalid';

                return null;
            }

            $days[] = (int) $part;
        }

        if (count($days) > 6) {
            $errors['reminder_days'] = 'error.reminder_days.too_many';

            return null;
        }

        rsort($days);

        return implode(',', array_unique($days));
    }

    private function isSamePrice(Money $a, Money $b): bool
    {
        return $a->currency === $b->currency && $a->amountMinor === $b->amountMinor;
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->subscriptions->delete($scope, $id);
    }

    /**
     * Attach or clear a subscription's logo.
     *
     * Separate from `update()` because the logo is a file: the form sends it as
     * a multipart part and the API has an endpoint of its own, so neither one
     * carries it in the field set that `validate()` sees. The stored path only
     * ever comes from LogoStorage, which chose the name itself.
     */
    public function setLogo(Scope $scope, int $id, ?string $logoPath): void
    {
        $this->subscriptions->update($scope, $id, ['logo_path' => $logoPath], $this->currentTagIds($scope, $id));
    }

    /**
     * Pause or resume.
     *
     * A cancelled subscription cannot be resumed from here: it is finished,
     * and the way back is `uncancel()`, which lands on Paused. Letting resume
     * skip that step would leave a row that is active and cancelled at once —
     * counted in every total while every screen called it cancelled.
     *
     * @throws ValidationException when resuming a cancelled subscription.
     * @throws \App\Security\ScopeViolationException when the row is not this
     *         scope's to change.
     */
    public function setActive(Scope $scope, int $id, bool $active): void
    {
        if ($active && $this->subscriptions->find($scope, $id)?->isCancelled() === true) {
            throw ValidationException::field('is_active', 'error.subscription.cancelled_resume');
        }

        $this->subscriptions->update($scope, $id, ['is_active' => $active], $this->currentTagIds($scope, $id));
    }

    /**
     * Cancel a subscription: finished, as of today.
     *
     * Available for anything, surfaced first on trials — and on a trial it is
     * what stops the conversion, because every catch-up query skips a
     * cancelled row. Cancelling twice keeps the first date.
     *
     * The write goes through the write predicate like any other, so a Viewer
     * never reaches it (the route refuses them) and a Contributor or an
     * ISOLATED member can cancel only their own.
     *
     * @throws \App\Security\ScopeViolationException when the row is not
     *         this scope's to change.
     */
    public function cancel(Scope $scope, int $id): void
    {
        $subscription = $this->subscriptions->findForWrite($scope, $id);
        if ($subscription === null) {
            throw \App\Security\ScopeViolationException::forRow('subscriptions', $id);
        }

        if ($subscription->isCancelled()) {
            return;
        }

        $this->subscriptions->setCancelled($scope, $id, $this->clock->today());
    }

    /**
     * Undo a cancel. The row returns to Paused, never straight to Active: an
     * accidental cancel corrected must not silently restart the charges.
     *
     * @throws \App\Security\ScopeViolationException
     */
    public function uncancel(Scope $scope, int $id): void
    {
        $subscription = $this->subscriptions->findForWrite($scope, $id);
        if ($subscription === null) {
            throw \App\Security\ScopeViolationException::forRow('subscriptions', $id);
        }

        if (!$subscription->isCancelled()) {
            return;
        }

        $this->subscriptions->setCancelled($scope, $id, null);
    }

    /**
     * Roll every overdue recurring subscription forward to its next due date.
     *
     * Run lazily whenever the dashboard or list is viewed, rather than by a
     * scheduled job: the scheduler exists in this phase but has no work
     * assigned to it until reminders arrive, and a subscription's date only
     * matters when somebody is looking at it.
     *
     * A Viewer never triggers it. Their role is read-only, and a GET that
     * quietly performs an UPDATE because the viewer's household predicate
     * happens to match is exactly the kind of write that should not exist. The
     * dates catch up the next time somebody who can write looks at them.
     *
     * @return int Number of subscriptions moved on.
     */
    public function advanceDuePayments(Scope $scope): int
    {
        if (!$scope->canWrite()) {
            return 0;
        }

        $today = $this->clock->today();
        $advanced = 0;

        foreach ($this->subscriptions->findOverdue($scope, $today) as $subscription) {
            $next = $this->nextDueDateFrom($subscription, $today);
            if ($next === null || $next == $subscription->nextPaymentDate) {
                continue;
            }

            $this->subscriptions->setNextPaymentDate($scope, $subscription->id, $next);
            $advanced++;
        }

        return $advanced;
    }

    /**
     * Advance a date by whole cycles until it is today or later.
     */
    public function nextDueDateFrom(Subscription $subscription, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $cycle = $subscription->billingCycle;
        $current = $subscription->nextPaymentDate;

        if ($cycle === null || $current === null || !$subscription->type->countsTowardsRecurringTotals()) {
            return null;
        }

        $iterations = 0;
        while ($current < $today->setTime(0, 0) && $iterations < self::MAX_ADVANCE_ITERATIONS) {
            $current = $cycle->advance($current, $subscription->cycleDays, $subscription->anchorDay);
            $iterations++;
        }

        return $current;
    }

    /**
     * Trials ending in the next $days days.
     *
     * @return list<Subscription>
     */
    public function trialsEndingSoon(Scope $scope, int $days): array
    {
        $today = $this->clock->today();

        return $this->subscriptions->findTrialsEnding($scope, $today, $today->modify(sprintf('+%d days', $days)));
    }

    /**
     * Every trial that has not converted yet, soonest conversion first.
     *
     * Not a window, unlike `trialsEndingSoon()`: this answers "what am I on a
     * trial of", which a trial three months out belongs to just as much as one
     * ending on Friday. The catch-up run converts trials whose end date has
     * passed, so what comes back is genuinely pre-conversion.
     *
     * @return list<Subscription>
     */
    public function trialsBeforeConversion(Scope $scope): array
    {
        return $this->subscriptions->findTrialsEnding($scope, $this->clock->today());
    }

    /**
     * @return list<Subscription>
     */
    public function upcoming(Scope $scope, int $days): array
    {
        $today = $this->clock->today();

        return $this->subscriptions->findUpcoming($scope, $today, $today->modify(sprintf('+%d days', $days)));
    }

    /**
     * Every in-scope subscription, unpaginated, for the dashboard figures.
     *
     * @return list<Subscription>
     */
    public function allForStats(Scope $scope, bool $activeOnly = true): array
    {
        return $this->subscriptions->findAllForStats($scope, $activeOnly);
    }

    /**
     * How many subscriptions in the household are private to another member
     * — a count only, for the backup screen to say what it leaves out.
     */
    public function countPrivateToOthers(Scope $scope): int
    {
        return $this->subscriptions->countPrivateToOthers($scope);
    }

    /**
     * The switched-off subscriptions, for the strip's paused figure.
     *
     * @return list<Subscription>
     */
    public function paused(Scope $scope): array
    {
        return $this->subscriptions->findPaused($scope);
    }

    /**
     * Turn submitted form data into validated column values.
     *
     * `$commits` is false on a dry run — the importer's preview calls this on
     * every row of a file to find out which of them would fail. Nothing that
     * *writes* may happen on that path: no tag is created, and no logo is
     * fetched. A preview of four hundred rows must not be four hundred
     * outbound requests for subscriptions the user has not yet agreed to
     * import.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<int>}
     * @throws ValidationException
     */
    private function validate(
        Scope $scope,
        array $input,
        ?int $existingId = null,
        bool $commits = true,
        bool $restoring = false,
    ): array {
        $errors = [];
        $existing = $existingId === null ? null : $this->subscriptions->find($scope, $existingId);

        $name = trim($this->str($input, 'name'));
        if ($name === '') {
            $errors['name'] = 'error.name.required';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'error.name.too_long_150';
        }

        $plan = trim($this->str($input, 'plan'));
        if (mb_strlen($plan) > 60) {
            $errors['plan'] = 'error.plan.too_long_60';
        }

        $currency = Currency::normalise($this->str($input, 'currency'));
        if (!Currency::isValidCode($currency)) {
            $errors['currency'] = 'error.currency.required';
            $currency = 'GBP';
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

        $type = SubscriptionType::tryFrom($this->str($input, 'subscription_type'))
            ?? SubscriptionType::Recurring;

        $cycle = null;
        $cycleDays = null;
        if ($type->hasBillingCycle()) {
            $cycle = BillingCycle::tryFromString($this->str($input, 'billing_cycle'));
            if ($cycle === null) {
                $errors['billing_cycle'] = 'error.cycle.required';
            } elseif ($cycle->requiresCycleDays()) {
                $cycleDays = (int) $this->str($input, 'cycle_days');
                if ($cycleDays < 1 || $cycleDays > 3650) {
                    $errors['cycle_days'] = 'error.cycle_days.range';
                }
            }
        }

        $nextPaymentDate = $this->date($this->str($input, 'next_payment_date'));
        $isTrial = ($input['is_trial'] ?? '0') === '1';
        if ($type->hasBillingCycle() && $nextPaymentDate === null && !$isTrial) {
            // A trial has no payment date yet — that is the point of it. The
            // conversion sets one when the trial ends.
            $errors['next_payment_date'] = 'error.next_payment.required';
        }
        if ($this->str($input, 'next_payment_date') !== '' && $nextPaymentDate === null) {
            $errors['next_payment_date'] = 'error.date.invalid';
        }

        $startDate = $this->date($this->str($input, 'start_date'));

        [$trial, $trialErrors] = $this->validateTrial($input, $type);
        $errors += $trialErrors;

        $noticeAmountRaw = trim($this->str($input, 'notice_period_amount'));
        $noticeAmount = $noticeAmountRaw === '' ? null : (int) $noticeAmountRaw;
        $noticeUnit = $this->str($input, 'notice_period_unit');
        if ($noticeAmount !== null && ($noticeAmount < 0 || $noticeAmount > 3650)) {
            $errors['notice_period_amount'] = 'error.notice.range';
        }
        if ($noticeUnit !== '' && !in_array($noticeUnit, NoticePeriod::units(), true)) {
            $errors['notice_period_unit'] = 'error.notice.unit_required';
        }
        $notice = NoticePeriod::of(
            isset($errors['notice_period_amount']) ? null : $noticeAmount,
            $noticeUnit === '' ? NoticePeriod::UNIT_DAYS : $noticeUnit,
        );

        $categoryId = $this->positiveInt($input['category_id'] ?? null);
        if ($categoryId !== null && $this->categories->find($scope, $categoryId) === null) {
            $errors['category_id'] = 'error.category.not_found';
        }

        $paymentMethodId = $this->positiveInt($input['payment_method_id'] ?? null);
        if ($paymentMethodId !== null && $this->paymentMethods->find($scope, $paymentMethodId) === null) {
            $errors['payment_method_id'] = 'error.payment_method.not_found';
        }

        $memberIds = array_map(
            static fn (array $member): int => $member['id'],
            $scope->hasHousehold() ? $this->memberships->findMembersOfHousehold((int) $scope->householdId) : [],
        );

        $ownerUserId = $this->positiveInt($input['owner_user_id'] ?? null) ?? $scope->userId;
        if ($scope->restrictsWritesToOwner()) {
            // Somebody fenced to their own rows may only ever own what they make.
            $ownerUserId = $scope->userId;
        } elseif (!in_array($ownerUserId, $memberIds, true)) {
            $errors['owner_user_id'] = 'error.member.not_in_household';
            $ownerUserId = $scope->userId;
        }

        $payerUserId = $this->positiveInt($input['payer_user_id'] ?? null);
        if ($payerUserId !== null && !in_array($payerUserId, $memberIds, true)) {
            $errors['payer_user_id'] = 'error.member.not_in_household';
            $payerUserId = null;
        }

        $visibility = $this->visibility($input, $existing, $ownerUserId, $payerUserId, $scope, $restoring, $errors);
        $cancelledAt = $restoring ? $this->date($this->str($input, 'cancelled_at')) : null;

        $website = $this->website($input, $errors);

        $notes = trim($this->str($input, 'notes'));
        if (mb_strlen($notes) > 5000) {
            $errors['notes'] = 'error.notes.too_long_5000';
        }

        // Null when the field was not submitted at all, which is what keeps a
        // form that does not show it — a bulk edit, a later API — from wiping a
        // per-subscription reminder schedule it never asked about.
        $reminderDays = $this->reminderDays($input, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var Money $price */
        $tagIds = $commits ? $this->tags->resolveOrCreate($scope, $this->tagNames($input)) : [];

        $data = [
            'name' => $name,
            'notes' => $notes === '' ? null : $notes,
            'website_url' => $website,
            'price_minor' => $price->amountMinor,
            'currency' => $currency,
            'subscription_type' => $type->value,
            'billing_cycle' => $cycle?->value,
            'cycle_days' => $cycleDays,
            'next_payment_date' => $nextPaymentDate?->format('Y-m-d'),
            'start_date' => $startDate?->format('Y-m-d'),
            // The anchor day is remembered so a subscription billed on the
            // 31st returns to the 31st after a short month.
            'anchor_day' => $nextPaymentDate !== null ? (int) $nextPaymentDate->format('j') : null,
            'notice_period_amount' => $notice->amount,
            'notice_period_unit' => $notice->isSet() ? $notice->unit : null,
            'reminder_days' => $reminderDays,
            'is_trial' => $trial['is_trial'],
            'trial_end_date' => $trial['trial_end_date'],
            'converts_to_price_minor' => $trial['converts_to_price_minor'],
            'converts_to_billing_cycle' => $trial['converts_to_billing_cycle'],
            'converts_to_cycle_days' => $trial['converts_to_cycle_days'],
            // A cancelled row stays switched off whatever the form, the API or
            // an import says: `cancelled_at` set means `is_active` false, and
            // only `uncancel()` may take the first step back.
            'is_active' => $existing?->isCancelled() === true || $cancelledAt !== null
                ? false
                : ($input['is_active'] ?? '1') !== '0',
            'plan' => $plan === '' ? null : $plan,
            'visibility' => $visibility->value,
            'category_id' => $categoryId,
            'payment_method_id' => $paymentMethodId,
            'owner_user_id' => $ownerUserId,
            'payer_user_id' => $payerUserId,
            'logo_path' => $this->logoPath($scope, $input, $existingId, $commits ? $website : null),
        ];

        if (!array_key_exists('reminder_days', $input)) {
            // Absent rather than empty: a form that does not carry the field
            // must not clear a schedule it never displayed.
            unset($data['reminder_days']);
        }

        if (!array_key_exists('payment_method_id', $input)) {
            // The same rule, for the same reason: an API client written before
            // payment methods existed does not send the field, and an edit it
            // makes must not unassign a method somebody chose on the form.
            unset($data['payment_method_id']);
        }

        // And again for the two Phase 20 fields. An absent visibility on an
        // edit keeps the current setting; it was still validated above,
        // because an edit that changes the owner of a private row is refused
        // whether or not it mentions the visibility.
        if ($cancelledAt !== null) {
            $data['cancelled_at'] = $cancelledAt->format('Y-m-d');
        }

        foreach (['visibility', 'plan'] as $column) {
            if ($existing !== null && !array_key_exists($column, $input)) {
                unset($data[$column]);
            }
        }

        return [$data, $tagIds];
    }

    /**
     * Who the subscription is visible to, and whether that is allowed.
     *
     * "Only me" means *the person saving it*. Three rules, each closing a way
     * to make a row disappear from under somebody:
     *
     *  - the owner must be the actor — an Owner/Admin marking another member's
     *    row private, or handing their own private row to somebody else, would
     *    create a row that vanished from their own screen on save;
     *  - the payer, if named, must be the owner — "private to its payer" is one
     *    person, and a payer who cannot see what they pay is nonsense;
     *  - it must not be split — a split is always visible to its participants,
     *    so the two cannot coexist. SplitService refuses the reverse.
     *
     * An absent field is the current setting (or Household on a new row), and
     * is checked all the same. A restore may put a private row back with the
     * member it belonged to — it is being returned, not handed over.
     *
     * @param array<string, mixed>                  $input
     * @param array<string, ValidationError|string> $errors
     */
    private function visibility(
        array $input,
        ?Subscription $existing,
        int $ownerUserId,
        ?int $payerUserId,
        Scope $scope,
        bool $restoring,
        array &$errors,
    ): Visibility {
        $visibility = array_key_exists('visibility', $input)
            ? Visibility::tryFromString($this->str($input, 'visibility'))
            : ($existing->visibility ?? Visibility::Household);

        if ($visibility === null) {
            $errors['visibility'] = 'error.visibility.invalid';

            return $existing->visibility ?? Visibility::Household;
        }

        if (!$visibility->isPrivate()) {
            return $visibility;
        }

        if ($ownerUserId !== $scope->userId && !$restoring) {
            $errors['visibility'] = 'error.visibility.owner_only';
        } elseif ($payerUserId !== null && $payerUserId !== $ownerUserId) {
            $errors['visibility'] = 'error.visibility.payer_is_owner';
        } elseif ($existing !== null && $existing->splitMode->isSplit()) {
            $errors['visibility'] = 'error.visibility.split';
        }

        return $visibility;
    }

    /**
     * The subscription's own web address.
     *
     * Only the scheme and a host are checked here. What it resolves to is not
     * this layer's business — the guarded HTTP client decides that at the
     * moment of a request, which is the only moment at which an answer is
     * true.
     *
     * @param array<string, mixed> $input
     * @param array<string, ValidationError|string> $errors
     */
    private function website(array $input, array &$errors): ?string
    {
        $url = trim($this->str($input, 'website_url'));
        if ($url === '') {
            return null;
        }

        if (!str_contains($url, '://')) {
            $url = 'https://' . $url;
        }

        if (mb_strlen($url) > 300) {
            $errors['website_url'] = 'error.website.too_long';

            return null;
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            $errors['website_url'] = 'error.url.invalid';

            return null;
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function logoPath(Scope $scope, array $input, ?int $existingId, ?string $website): ?string
    {
        // An upload always wins: somebody who chose a file meant it.
        $uploaded = $this->str($input, 'logo_path');
        if ($uploaded !== '') {
            return $uploaded;
        }

        $existing = $existingId === null ? null : $this->subscriptions->find($scope, $existingId)?->logoPath;
        if ($existing !== null) {
            return $existing;
        }

        // Nothing stored and a website given: ask the site for its icon. The
        // fetcher answers from its per-domain cache where it can, never throws,
        // and returns null when there is nothing to be had — so a save is never
        // held up by a site being slow to say no.
        return $this->logoFetcher->fetchFor($website);
    }

    /**
     * @return list<int>
     */
    private function currentTagIds(Scope $scope, int $id): array
    {
        $subscription = $this->subscriptions->find($scope, $id);
        if ($subscription === null) {
            return [];
        }

        return array_map(static fn ($tag): int => $tag->id, $subscription->tags);
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function tagNames(array $input): array
    {
        $raw = $input['tags'] ?? '';
        if (is_array($raw)) {
            $names = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $raw);
        } else {
            $names = explode(',', is_scalar($raw) ? (string) $raw : '');
        }

        $names = array_values(array_filter(array_map(
            static fn (string $name): string => mb_substr(trim($name), 0, 50),
            $names,
        ), static fn (string $name): bool => $name !== ''));

        // A sane upper bound: the field is free text, and nothing good comes
        // of a single subscription creating two hundred tags.
        return array_slice($names, 0, 25);
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
