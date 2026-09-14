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
use App\Persistence\Database;
use App\Repository\CategoryRepository;
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
        private readonly TagRepository $tags,
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
     * @throws ValidationException
     */
    public function create(Scope $scope, array $input): int
    {
        [$data, $tagIds] = $this->validate($scope, $input);

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
            $errors['trial_end_date'] = 'Enter the date the trial ends.';
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
                    $errors['converts_to_price'] = 'Enter a price of zero or more.';
                } else {
                    $convertsToMinor = $converts->amountMinor;
                }
            } catch (InvalidArgumentException) {
                $errors['converts_to_price'] = 'Enter the price it converts to, for example 9.99.';
            }
        }

        $convertsToCycle = BillingCycle::tryFromString($this->str($input, 'converts_to_billing_cycle'));
        $convertsToCycleDays = null;
        if ($convertsToCycle !== null && $convertsToCycle->requiresCycleDays()) {
            $convertsToCycleDays = (int) $this->str($input, 'converts_to_cycle_days');
            if ($convertsToCycleDays < 1 || $convertsToCycleDays > 3650) {
                $errors['converts_to_cycle_days'] = 'Enter the number of days between payments (1-3650).';
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

    private function isSamePrice(Money $a, Money $b): bool
    {
        return $a->currency === $b->currency && $a->amountMinor === $b->amountMinor;
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->subscriptions->delete($scope, $id);
    }

    public function setActive(Scope $scope, int $id, bool $active): void
    {
        $this->subscriptions->update($scope, $id, ['is_active' => $active], $this->currentTagIds($scope, $id));
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
     * @return list<string>
     */
    public function currenciesInUse(Scope $scope): array
    {
        return $this->subscriptions->distinctCurrencies($scope);
    }

    /**
     * Turn submitted form data into validated column values.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<int>}
     * @throws ValidationException
     */
    private function validate(Scope $scope, array $input, ?int $existingId = null): array
    {
        $errors = [];

        $name = trim($this->str($input, 'name'));
        if ($name === '') {
            $errors['name'] = 'Enter a name.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'Name must be 150 characters or fewer.';
        }

        $currency = Currency::normalise($this->str($input, 'currency'));
        if (!Currency::isValidCode($currency)) {
            $errors['currency'] = 'Choose a currency.';
            $currency = 'GBP';
        }

        $price = null;
        try {
            $price = Money::fromUserInput($this->str($input, 'price'), $currency);
            if ($price->isNegative()) {
                $errors['price'] = 'Enter a price of zero or more.';
            }
        } catch (InvalidArgumentException) {
            $errors['price'] = 'Enter a price, for example 9.99.';
        }

        $type = SubscriptionType::tryFrom($this->str($input, 'subscription_type'))
            ?? SubscriptionType::Recurring;

        $cycle = null;
        $cycleDays = null;
        if ($type->hasBillingCycle()) {
            $cycle = BillingCycle::tryFromString($this->str($input, 'billing_cycle'));
            if ($cycle === null) {
                $errors['billing_cycle'] = 'Choose a billing cycle.';
            } elseif ($cycle->requiresCycleDays()) {
                $cycleDays = (int) $this->str($input, 'cycle_days');
                if ($cycleDays < 1 || $cycleDays > 3650) {
                    $errors['cycle_days'] = 'Enter the number of days between payments (1–3650).';
                }
            }
        }

        $nextPaymentDate = $this->date($this->str($input, 'next_payment_date'));
        $isTrial = ($input['is_trial'] ?? '0') === '1';
        if ($type->hasBillingCycle() && $nextPaymentDate === null && !$isTrial) {
            // A trial has no payment date yet — that is the point of it. The
            // conversion sets one when the trial ends.
            $errors['next_payment_date'] = 'Enter the next payment date.';
        }
        if ($this->str($input, 'next_payment_date') !== '' && $nextPaymentDate === null) {
            $errors['next_payment_date'] = 'Enter a valid date.';
        }

        $startDate = $this->date($this->str($input, 'start_date'));

        [$trial, $trialErrors] = $this->validateTrial($input, $type);
        $errors += $trialErrors;

        $noticeAmountRaw = trim($this->str($input, 'notice_period_amount'));
        $noticeAmount = $noticeAmountRaw === '' ? null : (int) $noticeAmountRaw;
        $noticeUnit = $this->str($input, 'notice_period_unit');
        if ($noticeAmount !== null && ($noticeAmount < 0 || $noticeAmount > 3650)) {
            $errors['notice_period_amount'] = 'Enter a notice period between 0 and 3650.';
        }
        if ($noticeUnit !== '' && !in_array($noticeUnit, NoticePeriod::units(), true)) {
            $errors['notice_period_unit'] = 'Choose a notice period unit.';
        }
        $notice = NoticePeriod::of(
            isset($errors['notice_period_amount']) ? null : $noticeAmount,
            $noticeUnit === '' ? NoticePeriod::UNIT_DAYS : $noticeUnit,
        );

        $categoryId = $this->positiveInt($input['category_id'] ?? null);
        if ($categoryId !== null && $this->categories->find($scope, $categoryId) === null) {
            $errors['category_id'] = 'That category does not exist.';
        }

        $memberIds = array_map(
            static fn (array $member): int => $member['id'],
            $scope->hasHousehold() ? $this->memberships->findMembersOfHousehold((int) $scope->householdId) : [],
        );

        $ownerUserId = $this->positiveInt($input['owner_user_id'] ?? null) ?? $scope->userId;
        if ($scope->isOwnerRestricted()) {
            // In ISOLATED mode a user may only ever own their own rows.
            $ownerUserId = $scope->userId;
        } elseif (!in_array($ownerUserId, $memberIds, true)) {
            $errors['owner_user_id'] = 'Choose a member of this household.';
            $ownerUserId = $scope->userId;
        }

        $payerUserId = $this->positiveInt($input['payer_user_id'] ?? null);
        if ($payerUserId !== null && !in_array($payerUserId, $memberIds, true)) {
            $errors['payer_user_id'] = 'Choose a member of this household.';
            $payerUserId = null;
        }

        $notes = trim($this->str($input, 'notes'));
        if (mb_strlen($notes) > 5000) {
            $errors['notes'] = 'Notes must be 5000 characters or fewer.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var Money $price */
        $tagIds = $this->tags->resolveOrCreate($scope, $this->tagNames($input));

        $data = [
            'name' => $name,
            'notes' => $notes === '' ? null : $notes,
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
            'is_trial' => $trial['is_trial'],
            'trial_end_date' => $trial['trial_end_date'],
            'converts_to_price_minor' => $trial['converts_to_price_minor'],
            'converts_to_billing_cycle' => $trial['converts_to_billing_cycle'],
            'converts_to_cycle_days' => $trial['converts_to_cycle_days'],
            'is_active' => ($input['is_active'] ?? '1') !== '0',
            'category_id' => $categoryId,
            'owner_user_id' => $ownerUserId,
            'payer_user_id' => $payerUserId,
            'logo_path' => $this->logoPath($scope, $input, $existingId),
        ];

        return [$data, $tagIds];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function logoPath(Scope $scope, array $input, ?int $existingId): ?string
    {
        // Logos are stored, never fetched: the value here only ever comes from
        // an upload this application wrote itself, or from the existing row.
        $uploaded = $this->str($input, 'logo_path');
        if ($uploaded !== '') {
            return $uploaded;
        }

        if ($existingId === null) {
            return null;
        }

        return $this->subscriptions->find($scope, $existingId)?->logoPath;
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
