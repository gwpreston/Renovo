<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionSplit;
use App\Domain\Permission;
use App\Domain\SplitMode;
use App\Domain\Visibility;
use App\Persistence\Database;
use App\Security\PermissionService;
use App\Security\Scope;

/**
 * The subscription form's own shape, translated onto the services behind it.
 *
 * The form asks two things the API does not: how the cost is split, and when
 * to be reminded as a set of chips rather than a typed list. Neither is a new
 * rule. The split is `SplitService::update()` and the reminders are the
 * three-state `reminder_days` field `SubscriptionService` has always parsed;
 * what lives here is the arrangement of one form over both, and the one
 * transaction that makes "save" mean the row and its split together or
 * neither.
 *
 * The API keeps calling `SubscriptionService` directly, with the input it has
 * always sent, so nothing about its contract moves.
 */
final class SubscriptionFormService
{
    /** The reminder chips the form offers, in days before the charge. */
    public const REMINDER_CHOICES = [1, 3, 7, 14, 30];

    public const REMINDER_DEFAULT = 'default';
    public const REMINDER_NEVER = 'never';
    public const REMINDER_DAYS = 'days';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SplitService $splits,
        private readonly PermissionService $permissions,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(Scope $scope, array $input): int
    {
        $split = $this->splitInput($scope, $input);
        $input = $this->normalise($input);

        return $this->db->transactional(function () use ($scope, $input, $split): int {
            $id = $this->subscriptions->create($scope, $input);

            if ($split !== null && $split['mode']->isSplit()) {
                $this->splits->update($scope, $id, $split['input']);
            }

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(Scope $scope, int $id, array $input): void
    {
        $split = $this->splitInput($scope, $input);
        $input = $this->normalise($input);

        $this->db->transactional(function () use ($scope, $id, $input, $split): void {
            // The order is the rule "a private row cannot be split", met from
            // both directions. Taking a split away happens first, so the same
            // save can then make the row private; putting one on happens last,
            // after the row has been confirmed as visible to the household.
            if ($split !== null && !$split['mode']->isSplit()) {
                $this->splits->update($scope, $id, $split['input']);
            }

            $this->subscriptions->update($scope, $id, $input);

            if ($split !== null && $split['mode']->isSplit()) {
                $this->splits->update($scope, $id, $split['input']);
            }
        });
    }

    /**
     * What the reminder chips show for a stored `reminder_days`.
     *
     * @return array{reminder_mode: string, reminder_day: list<int>}
     */
    public function reminderValues(?string $reminderDays): array
    {
        if ($reminderDays === null) {
            return ['reminder_mode' => self::REMINDER_DEFAULT, 'reminder_day' => []];
        }

        if ($reminderDays === '') {
            return ['reminder_mode' => self::REMINDER_NEVER, 'reminder_day' => []];
        }

        return ['reminder_mode' => self::REMINDER_DAYS, 'reminder_day' => $this->days($reminderDays)];
    }

    /**
     * The day chips to draw: the standard five, plus any day already chosen
     * that is not one of them.
     *
     * A schedule set before the chips existed — "60, 14" typed into the old
     * box, or sent through the API — is still valid, and an edit must not drop
     * the 60 because the form had no chip for it. It gets one of its own.
     *
     * @param list<int> $chosen
     * @return list<int>
     */
    public function reminderChoices(array $chosen): array
    {
        $choices = array_values(array_unique([...self::REMINDER_CHOICES, ...$chosen]));
        sort($choices);

        return $choices;
    }

    /**
     * What the split controls show for an existing subscription.
     *
     * @param list<SubscriptionSplit> $participants
     * @return array{split_mode: string, split_with: list<int>, shares: array<int, int>}
     */
    public function splitValues(Subscription $subscription, array $participants): array
    {
        $shares = [];
        foreach ($participants as $participant) {
            $shares[$participant->userId] = $participant->shareUnits;
        }

        return [
            'split_mode' => $subscription->splitMode->value,
            'split_with' => array_keys($shares),
            'shares' => $shares,
        ];
    }

    /**
     * Whether this scope may set the split from the form for this row (or,
     * with no row, for one it is about to create).
     */
    public function mayEditSplit(Scope $scope, ?Subscription $subscription): bool
    {
        if (!$this->permissions->allows($scope, Permission::ManageSplits) || !$scope->canWrite()) {
            return false;
        }

        return $subscription === null || $this->splits->canEdit($scope, $subscription);
    }

    /**
     * The split the form asked for, in the shape `SplitService::update()`
     * reads — or null when the form carried no split controls at all, which
     * leaves the arrangement exactly as it was.
     *
     * An equal split is chosen by ticking members; a custom one by a weight
     * per member. Both become `shares`, which is the one input the split
     * service has, so its validation — members of this household only, at
     * least one participant, weights in range — is applied unchanged.
     *
     * @param array<string, mixed> $input
     * @return array{mode: SplitMode, input: array<string, mixed>}|null
     * @throws ValidationException
     */
    private function splitInput(Scope $scope, array $input): ?array
    {
        if (!array_key_exists('split_mode', $input)) {
            return null;
        }

        $mode = SplitMode::tryFromString(is_scalar($input['split_mode']) ? (string) $input['split_mode'] : null)
            ?? SplitMode::None;

        // A form rendered for somebody who may not split is not sent the
        // controls; one that arrives with them anyway is refused rather than
        // quietly half-saved.
        if ($mode->isSplit() && !$this->mayEditSplit($scope, null)) {
            throw ValidationException::field('split_mode', 'error.split.owner_only');
        }

        $shares = [];
        if ($mode === SplitMode::Equal) {
            $with = $input['split_with'] ?? [];
            foreach (is_array($with) ? $with : [] as $userId) {
                if (is_scalar($userId) && (int) $userId > 0) {
                    $shares[(int) $userId] = 1;
                }
            }
        } elseif ($mode === SplitMode::Custom) {
            $raw = $input['shares'] ?? [];
            foreach (is_array($raw) ? $raw : [] as $userId => $units) {
                $shares[(int) $userId] = is_scalar($units) ? (int) $units : 0;
            }
        }

        // "Only me" and a split cannot both be true: a split is always seen
        // by the people in it. Checked here because this is the one place the
        // two arrive together; SubscriptionService and SplitService each
        // refuse the combination against what is already stored.
        $visibility = is_scalar($input['visibility'] ?? null) ? (string) $input['visibility'] : '';
        if ($mode->isSplit() && Visibility::tryFromString($visibility)?->isPrivate() === true) {
            throw ValidationException::field('visibility', 'error.visibility.split');
        }

        return ['mode' => $mode, 'input' => ['split_mode' => $mode->value, 'shares' => $shares]];
    }

    /**
     * The form's fields as `SubscriptionService` reads them.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function normalise(array $input): array
    {
        unset($input['split_mode'], $input['split_with'], $input['shares']);

        if (!array_key_exists('reminder_mode', $input)) {
            return $input;
        }

        $mode = is_scalar($input['reminder_mode']) ? (string) $input['reminder_mode'] : '';
        $chosen = $input['reminder_day'] ?? [];
        $days = [];
        foreach (is_array($chosen) ? $chosen : [] as $day) {
            if (is_scalar($day) && trim((string) $day) !== '') {
                $days[] = trim((string) $day);
            }
        }

        $input['reminder_days'] = match ($mode) {
            // "none" is the service's word for "never remind me about this one".
            self::REMINDER_NEVER => 'none',
            self::REMINDER_DAYS => implode(',', $days),
            default => '',
        };

        if ($mode === self::REMINDER_DAYS && $days === []) {
            throw ValidationException::field('reminder_days', 'error.reminder_days.none_chosen');
        }

        unset($input['reminder_mode'], $input['reminder_day']);

        return $input;
    }

    /**
     * @return list<int>
     */
    private function days(string $reminderDays): array
    {
        $days = [];
        foreach (explode(',', $reminderDays) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $days[] = (int) $part;
            }
        }

        return $days;
    }
}
