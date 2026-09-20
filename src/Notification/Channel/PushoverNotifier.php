<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\User;
use App\Notification\Alert;
use App\Notification\ChannelField;
use App\Notification\Notifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use JsonException;

/**
 * Push notifications through Pushover.
 *
 * Form-encoded rather than JSON: `api.pushover.net/1/messages.json` documents
 * `application/x-www-form-urlencoded`, and the `.json` in the path describes
 * what comes back, not what goes in.
 *
 * Both credentials are secrets — the application token identifies the app, the
 * user key identifies the recipient, and either one in the wrong hands sends
 * messages in your name. So both are `ChannelField::secret`, neither is ever
 * re-rendered, and `describe()` has nothing safe to return; it names the
 * service instead of masking a key, because a masked key still leaks its
 * length and its tail.
 *
 * **Priority 2 is deliberately not offered.** Pushover's emergency priority
 * requires `retry` and `expire`, and repeats the alert until a human
 * acknowledges it. A renewal reminder that rings every 30 seconds until
 * dismissed is not a feature, and supporting it properly means acknowledgement
 * receipts, which is a different phase's worth of work.
 */
final class PushoverNotifier extends HttpNotifier implements Notifier
{
    private const ENDPOINT = 'https://api.pushover.net/1/messages.json';

    /** Pushover truncates the body at 1024 characters. */
    private const MAX_MESSAGE = 1024;

    public function key(): string
    {
        return 'pushover';
    }

    public function label(): string
    {
        return 'Pushover';
    }

    public function fields(): array
    {
        return [
            ChannelField::secret('token', 'channel_field.pushover.token', 'channel_field.pushover.token_hint'),
            ChannelField::secret('user_key', 'channel_field.pushover.user_key', 'channel_field.pushover.user_key_hint'),
            new ChannelField(
                'priority',
                'channel_field.pushover.priority',
                'number',
                false,
                'channel_field.pushover.priority_hint',
            ),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        $token = $this->keepOrRequire(
            'token',
            $input,
            $existing,
            'error.pushover.token_required',
            'error.pushover.token_invalid',
        );
        $userKey = $this->keepOrRequire(
            'user_key',
            $input,
            $existing,
            'error.pushover.user_key_required',
            'error.pushover.user_key_invalid',
        );

        $priority = trim($input['priority'] ?? '');
        if ($priority !== '') {
            if (preg_match('/^-?\d+$/', $priority) !== 1) {
                throw ValidationException::field('priority', 'error.pushover.priority_range');
            }

            $value = (int) $priority;
            // -2 silent through 1 high. 2 is emergency; see the class comment.
            if ($value < -2 || $value > 1) {
                throw ValidationException::field('priority', 'error.pushover.priority_range');
            }
        }

        return [
            'token' => $token,
            'user_key' => $userKey,
            'priority' => $priority === '' ? '0' : $priority,
        ];
    }

    /**
     * A blank secret on an existing channel means "leave it alone" — the form
     * never showed the stored value, so it cannot ask for it to be retyped.
     *
     * @param array<string, string> $input
     * @param array<string, string> $existing
     * @throws ValidationException
     */
    private function keepOrRequire(
        string $field,
        array $input,
        array $existing,
        string $requiredKey,
        string $invalidKey,
    ): string {
        $value = trim($input[$field] ?? '');
        if ($value === '') {
            $value = trim($existing[$field] ?? '');
        }

        if ($value === '') {
            throw ValidationException::field($field, $requiredKey);
        }

        // Pushover's keys are fixed-length alphanumerics. Checking here turns a
        // mistyped key into a message beside the field rather than a
        // notification that silently never arrives.
        if (preg_match('/^[A-Za-z0-9]{30}$/', $value) !== 1) {
            throw ValidationException::field($field, $invalidKey);
        }

        return $value;
    }

    public function describe(array $config): string
    {
        // Both fields are credentials; there is nothing here safe to show.
        return 'Pushover';
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $token = $channel->config('token');
        $userKey = $channel->config('user_key');

        if ($token === '' || $userKey === '') {
            throw NotifierException::misconfigured($this->label(), 'the application token or user key is missing');
        }

        $fields = [
            'token' => $token,
            'user' => $userKey,
            'title' => $alert->title,
            'message' => mb_substr($alert->body() === '' ? $alert->title : $alert->body(), 0, self::MAX_MESSAGE),
            'priority' => $channel->config('priority', '0'),
        ];

        if ($alert->url !== null) {
            $fields['url'] = $alert->url;
        }

        $response = $this->postForm(self::ENDPOINT, $fields, [], true);

        try {
            $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw NotifierException::rejected(
                $this->label(),
                'its response could not be read (HTTP ' . $response->getStatusCode() . ')',
            );
        }

        if (is_array($decoded) && ($decoded['status'] ?? 0) === 1) {
            return;
        }

        throw NotifierException::rejected($this->label(), $this->explain($decoded));
    }

    /**
     * Pushover returns `{"status":0,"errors":["user identifier is invalid"]}`.
     * The errors are already written for a human, so they are joined and
     * passed through.
     */
    private function explain(mixed $decoded): string
    {
        if (!is_array($decoded) || !is_array($decoded['errors'] ?? null)) {
            return 'it reported a failure';
        }

        $errors = array_values(array_filter($decoded['errors'], 'is_string'));

        return $errors === [] ? 'it reported a failure' : implode('; ', $errors);
    }
}
