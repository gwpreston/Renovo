<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Domain\AlertType;
use App\Domain\DigestMode;
use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\NotificationPreferences;
use App\Notification\NotifierRegistry;
use App\Repository\NotificationChannelRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRouteRepository;
use App\Service\ValidationException;

/**
 * A user's channels, preferences and routing.
 *
 * Everything here is scoped to one account by its user id — a channel is
 * personal, and the household isolation mode has nothing to say about it. What
 * the service adds over the three repositories is the rules that connect them:
 *
 *  - a channel's configuration is validated by the notifier that will use it,
 *    so the settings form never has to know what a Gotify token looks like;
 *  - a secret left blank keeps its stored value, because the form deliberately
 *    cannot show it;
 *  - **no routing rows means everything goes everywhere.** This is the default
 *    a new channel lands in, and it is the difference between adding a channel
 *    and having it work, and adding a channel and wondering why it is silent.
 */
final class NotificationSettingsService
{
    private const MAX_LEAD_TIMES = 6;
    private const MAX_LEAD_DAYS = 365;

    public function __construct(
        private readonly NotificationChannelRepository $channels,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly NotificationRouteRepository $routes,
        private readonly NotifierRegistry $notifiers,
    ) {
    }

    /**
     * @return list<NotificationChannel>
     */
    public function channels(int $userId, bool $activeOnly = false): array
    {
        return $this->channels->findAllForUser($userId, $activeOnly);
    }

    public function channel(int $userId, int $id): ?NotificationChannel
    {
        return $this->channels->find($userId, $id);
    }

    /**
     * @param array<string, string> $input
     * @throws ValidationException
     */
    public function createChannel(int $userId, array $input): int
    {
        $type = trim($input['channel_type'] ?? '');
        $notifier = $this->notifiers->find($type);

        if ($notifier === null) {
            throw ValidationException::field('channel_type', 'Choose a channel type.');
        }

        $label = $this->label($input, $notifier->label());
        $config = $notifier->normaliseConfig($input);

        return $this->channels->create($userId, $notifier->key(), $label, $config, $this->isActive($input));
    }

    /**
     * @param array<string, string> $input
     * @throws ValidationException
     */
    public function updateChannel(int $userId, int $id, array $input): void
    {
        $existing = $this->channels->find($userId, $id);
        if ($existing === null) {
            throw ValidationException::field('channel_type', 'That channel no longer exists.');
        }

        $notifier = $this->notifiers->find($existing->type);
        if ($notifier === null) {
            throw ValidationException::field('channel_type', 'That channel type is no longer available.');
        }

        $config = $notifier->normaliseConfig($input, $existing->config);

        $this->channels->update(
            $userId,
            $id,
            $this->label($input, $existing->label),
            $config,
            $this->isActive($input),
        );
    }

    public function deleteChannel(int $userId, int $id): void
    {
        $this->channels->delete($userId, $id);
    }

    public function preferences(int $userId): NotificationPreferences
    {
        return $this->preferences->findForUser($userId);
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function savePreferences(int $userId, array $input): void
    {
        $errors = [];

        $leadDays = $this->parseLeadDays($this->str($input, 'lead_days'), $errors);

        $mode = DigestMode::tryFromString($this->str($input, 'digest_mode')) ?? DigestMode::Immediate;

        $dayRaw = trim($this->str($input, 'digest_day'));
        $day = $dayRaw === '' ? 1 : (int) $dayRaw;

        if ($mode === DigestMode::Weekly && ($day < 1 || $day > 7)) {
            $errors['digest_day'] = 'Choose a day of the week.';
        }

        // Capped at 28 rather than 31: a monthly digest set for the 30th would
        // never be sent in February, and a notification feature that silently
        // skips a month is worse than one that is a few days early.
        if ($mode === DigestMode::Monthly && ($day < 1 || $day > 28)) {
            $errors['digest_day'] = 'Choose a day of the month between 1 and 28.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->preferences->save(new NotificationPreferences($userId, $leadDays, $mode, $day));
    }

    /**
     * @return array<int, list<string>> Channel id => alert type values.
     */
    public function routes(int $userId): array
    {
        return $this->routes->findForUser($userId);
    }

    /**
     * Save routing from the settings form.
     *
     * The form submits a checkbox per channel per alert type. A channel with
     * every box ticked is stored as four rows rather than as "all", so that
     * adding a fifth alert type in a later phase does not silently subscribe
     * everybody to it.
     *
     * @param array<int|string, mixed> $input routes[channelId][] = alertType
     */
    public function saveRoutes(int $userId, array $input): void
    {
        $known = [];
        foreach ($this->channels->findAllForUser($userId) as $channel) {
            $known[$channel->id] = true;
        }

        $routes = [];
        foreach ($input as $channelId => $types) {
            $channelId = (int) $channelId;
            if (!isset($known[$channelId]) || !is_array($types)) {
                continue;
            }

            foreach ($types as $type) {
                $alert = AlertType::tryFromString(is_scalar($type) ? (string) $type : null);
                if ($alert !== null) {
                    $routes[$channelId][] = $alert;
                }
            }
        }

        $this->routes->replaceForUser($userId, $routes);
    }

    /**
     * The active channels one alert type should be delivered on.
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(int $userId, AlertType $type): array
    {
        $channels = $this->channels->findAllForUser($userId, true);
        if ($channels === []) {
            return [];
        }

        $routes = $this->routes->findForUser($userId);
        if ($routes === []) {
            // Nothing configured: everything goes everywhere. See the class
            // docblock — this is the default, not a fallback.
            return $channels;
        }

        $selected = [];
        foreach ($channels as $channel) {
            $allowed = $routes[$channel->id] ?? null;

            // A channel with no rows of its own in a user's otherwise-populated
            // routing is one they have turned everything off for. Treating it
            // as "all" here would resurrect a channel they deliberately muted.
            if ($allowed !== null && in_array($type->value, $allowed, true)) {
                $selected[] = $channel;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, string> $errors
     * @return list<int>
     */
    private function parseLeadDays(string $value, array &$errors): array
    {
        $value = trim($value);
        if ($value === '') {
            // An empty list is a deliberate "no reminders before a charge". The
            // other alert types are unaffected.
            return [];
        }

        $days = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (!ctype_digit($part) || (int) $part > self::MAX_LEAD_DAYS) {
                $errors['lead_days'] = 'Enter whole numbers of days, separated by commas — for example 30, 7, 1.';

                return [];
            }

            $days[] = (int) $part;
        }

        if (count($days) > self::MAX_LEAD_TIMES) {
            $errors['lead_days'] = sprintf('Use at most %d reminders per subscription.', self::MAX_LEAD_TIMES);

            return [];
        }

        return $days;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function label(array $input, string $fallback): string
    {
        $label = trim($this->str($input, 'label'));

        return $label === '' ? $fallback : mb_substr($label, 0, 100);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isActive(array $input): bool
    {
        return ($input['is_active'] ?? '1') !== '0';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
