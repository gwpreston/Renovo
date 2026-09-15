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
 * Messages to a Slack channel or a direct message, via `chat.postMessage`.
 *
 * A bot token rather than an incoming webhook, because the phase asks for
 * "channel or user" and an incoming webhook is bound to the one channel it was
 * created for. With `chat.postMessage` the destination is an argument: a public
 * or private channel id, or a user id for a DM.
 *
 * **Slack reports failures with HTTP 200.** A missing scope, a bad token, a
 * channel the bot is not in — all of them come back as a perfectly successful
 * response whose body says `{"ok": false, "error": "not_in_channel"}`. A
 * notifier that only looked at the status code would mark every one of those as
 * delivered and the user would never learn why nothing arrives. So the body is
 * parsed, and `ok` is what decides.
 */
final class SlackNotifier extends HttpNotifier implements Notifier
{
    private const ENDPOINT = 'https://slack.com/api/chat.postMessage';

    public function key(): string
    {
        return 'slack';
    }

    public function label(): string
    {
        return 'Slack';
    }

    public function fields(): array
    {
        return [
            ChannelField::secret('token', 'Bot token', 'Starts with xoxb-. Needs the chat:write scope.'),
            new ChannelField(
                'channel',
                'Channel or user',
                'text',
                true,
                'A channel id (C0123…), a channel name (#bills) or a user id (U0123…) for a direct message.',
            ),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        $token = trim($input['token'] ?? '');
        if ($token === '') {
            $token = trim($existing['token'] ?? '');
        }

        if ($token === '') {
            throw ValidationException::field('token', 'Enter the Slack bot token.');
        }

        $channel = trim($input['channel'] ?? '');
        if ($channel === '') {
            throw ValidationException::field('channel', 'Enter the channel or user to message.');
        }

        if (mb_strlen($channel) > 100) {
            throw ValidationException::field('channel', 'That does not look like a channel or user id.');
        }

        return ['token' => $token, 'channel' => $channel];
    }

    public function describe(array $config): string
    {
        return $config['channel'] ?? '';
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $text = '*' . $alert->title . '*';
        if ($alert->lines !== []) {
            $text .= "\n" . $alert->body();
        }
        if ($alert->url !== null) {
            $text .= "\n" . $alert->url;
        }

        $response = $this->postJson(
            self::ENDPOINT,
            [
                'channel' => $channel->config('channel'),
                'text' => $text,
                // Slack renders the text itself; asking it not to unfurl keeps a
                // notification from turning into a page-sized preview card.
                'unfurl_links' => false,
            ],
            ['Authorization' => 'Bearer ' . $channel->config('token')],
            // Slack is a public service on https. Unlike a self-hosted Gotify
            // there is no legitimate plain-http variant, so no allowlist entry
            // can downgrade this one.
            true,
        );

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw NotifierException::rejected($this->label(), 'it answered HTTP ' . $status);
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw NotifierException::rejected($this->label(), 'its response could not be read');
        }

        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $error = is_array($decoded) && is_string($decoded['error'] ?? null)
                ? $decoded['error']
                : 'it reported a failure';

            throw NotifierException::rejected($this->label(), $this->explain($error));
        }
    }

    /**
     * Turn Slack's error slugs into something a user can act on. Anything
     * unrecognised is passed through rather than swallowed.
     */
    private function explain(string $error): string
    {
        return match ($error) {
            'invalid_auth', 'not_authed', 'token_revoked' => 'the bot token was not accepted',
            'missing_scope' => 'the bot token is missing the chat:write scope',
            'channel_not_found' => 'that channel or user could not be found',
            'not_in_channel' => 'the bot has not been invited to that channel',
            'is_archived' => 'that channel is archived',
            default => $error,
        };
    }
}
