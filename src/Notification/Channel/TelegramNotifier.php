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
 * Messages through a Telegram bot, via the Bot API's `sendMessage`.
 *
 * **Telegram, like Slack, disagrees with its own status code.** The body is
 * what decides: `{"ok": false, "error_code": 400, "description": "chat not
 * found"}`. The `description` is written for a human and is passed through
 * when it is not one of the cases worth rephrasing, because Telegram's own
 * words are usually the most useful thing available.
 *
 * The bot token goes in the URL — `api.telegram.org/bot<token>/sendMessage` —
 * because that is the only place the Bot API accepts it. That is not a choice
 * this class gets to make, so it compensates: `describe()` shows the chat id
 * rather than the endpoint, and `redact()` strips the token out of transport
 * errors before they are stored.
 *
 * Text is sent unformatted. A `parse_mode` would mean every subscription name
 * containing an underscore or an asterisk either breaks the message or has to
 * be escaped against a moving target, and an alert gains nothing from bold.
 */
final class TelegramNotifier extends HttpNotifier implements Notifier
{
    private const ENDPOINT = 'https://api.telegram.org/bot%s/sendMessage';

    /** Telegram rejects a message over 4096 characters outright. */
    private const MAX_TEXT = 4096;

    public function key(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return 'Telegram';
    }

    public function fields(): array
    {
        return [
            ChannelField::secret('token', 'channel_field.telegram.token', 'channel_field.telegram.token_hint'),
            new ChannelField(
                'chat_id',
                'channel_field.telegram.chat_id',
                'text',
                true,
                'channel_field.telegram.chat_id_hint',
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
            throw ValidationException::field('token', 'error.telegram.token_required');
        }

        // A bot token is `<bot id>:<secret>`, and the halves are what the URL
        // is built from. Anything else would produce a 404 from a path that
        // cannot be shown in the error, so it is caught at the field instead.
        if (preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw ValidationException::field('token', 'error.telegram.token_invalid');
        }

        $chatId = trim($input['chat_id'] ?? '');
        if ($chatId === '') {
            throw ValidationException::field('chat_id', 'error.telegram.chat_id_required');
        }

        // Numeric for a user, negative for a group or channel, `@name` for a
        // public channel. Everything else is a typo.
        if (preg_match('/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{4,})$/', $chatId) !== 1) {
            throw ValidationException::field('chat_id', 'error.telegram.chat_id_invalid');
        }

        return ['token' => $token, 'chat_id' => $chatId];
    }

    public function describe(array $config): string
    {
        return $config['chat_id'] ?? '';
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $token = $channel->config('token');
        if ($token === '') {
            throw NotifierException::misconfigured($this->label(), 'no bot token is set');
        }

        $text = $alert->title;
        if ($alert->lines !== []) {
            $text .= "\n\n" . $alert->body();
        }
        if ($alert->url !== null) {
            $text .= "\n\n" . $alert->url;
        }

        $response = $this->postJson(
            sprintf(self::ENDPOINT, $token),
            [
                'chat_id' => $channel->config('chat_id'),
                'text' => mb_substr($text, 0, self::MAX_TEXT),
                'disable_web_page_preview' => true,
            ],
            [],
            // A public service on https; no self-hosted variant exists, so the
            // allowlist cannot downgrade this one.
            true,
        );

        try {
            $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw NotifierException::rejected(
                $this->label(),
                'its response could not be read (HTTP ' . $response->getStatusCode() . ')',
            );
        }

        if (is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            return;
        }

        $description = is_array($decoded) && is_string($decoded['description'] ?? null)
            ? $decoded['description']
            : 'it reported a failure';

        throw NotifierException::rejected($this->label(), $this->explain($description));
    }

    /**
     * Telegram's descriptions are prose, not slugs, so the few that are worth
     * rephrasing are matched loosely and the rest are passed through intact.
     */
    private function explain(string $description): string
    {
        $lower = strtolower($description);

        return match (true) {
            str_contains($lower, 'unauthorized') => 'the bot token was not accepted',
            str_contains($lower, 'chat not found') =>
                'that chat could not be found — send the bot a message first, or check the chat id',
            str_contains($lower, 'bot was blocked') => 'the recipient has blocked the bot',
            str_contains($lower, 'not enough rights'), str_contains($lower, 'not a member') =>
                'the bot is not allowed to post in that chat',
            default => $description,
        };
    }

    protected function redact(string $message): string
    {
        // The token is in the path, and transport errors name the path.
        return (string) preg_replace('#/bot\d+:[^/\s]*#i', '/bot...', $message);
    }
}
