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
 * Messages to WeChat through Pushplus (推送加).
 *
 * **HTTP 200 does not mean delivered.** Pushplus answers with
 * `{"code": 200, "msg": "...", "data": "..."}`, and the `code` is what decides
 * — an invalid token comes back as a perfectly successful response whose body
 * says otherwise, the same trap Slack sets. Note the narrower claim even on
 * success: `code: 200` means Pushplus *accepted* the message for asynchronous
 * delivery, not that WeChat showed it. There is no synchronous way to learn
 * more, so accepted is treated as sent and the honest limit is recorded here
 * rather than implied by the code.
 *
 * `template: txt` because the default is `html`, and an alert body is plain
 * text with newlines — rendered as HTML the line breaks would vanish.
 */
final class PushplusNotifier extends HttpNotifier implements Notifier
{
    /**
     * PHASE.md specifies this host; the docs also serve the same endpoint at
     * `www.pushplus.plus/send`. Both are the same service.
     */
    private const ENDPOINT = 'https://api.pushplus.plus/send';

    public function key(): string
    {
        return 'pushplus';
    }

    public function label(): string
    {
        return 'Pushplus';
    }

    public function fields(): array
    {
        return [
            ChannelField::secret('token', 'channel_field.pushplus.token', 'channel_field.pushplus.token_hint'),
            ChannelField::optional('topic', 'channel_field.pushplus.topic', 'channel_field.pushplus.topic_hint'),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        $token = trim($input['token'] ?? '');
        if ($token === '') {
            $token = trim($existing['token'] ?? '');
        }

        if ($token === '') {
            throw ValidationException::field('token', 'error.pushplus.token_required');
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $token) !== 1) {
            throw ValidationException::field('token', 'error.pushplus.token_invalid');
        }

        $topic = trim($input['topic'] ?? '');
        if ($topic !== '' && mb_strlen($topic) > 100) {
            throw ValidationException::field('topic', 'error.pushplus.topic_invalid');
        }

        return ['token' => $token, 'topic' => $topic];
    }

    public function describe(array $config): string
    {
        // The token is the only other field, and it is a secret. A topic means
        // a group; no topic means the token's own WeChat account.
        $topic = trim($config['topic'] ?? '');

        return $topic === '' ? 'Pushplus' : 'Pushplus: ' . $topic;
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $token = $channel->config('token');
        if ($token === '') {
            throw NotifierException::misconfigured($this->label(), 'no token is set');
        }

        $content = $alert->body();
        if ($alert->url !== null) {
            $content .= "\n\n" . $alert->url;
        }

        $payload = [
            'token' => $token,
            'title' => $alert->title,
            'content' => $content === '' ? $alert->title : $content,
            'template' => 'txt',
        ];

        if ($channel->hasConfig('topic')) {
            $payload['topic'] = $channel->config('topic');
        }

        $response = $this->postJson(self::ENDPOINT, $payload, [], true);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw NotifierException::rejected($this->label(), 'it answered HTTP ' . $status);
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw NotifierException::rejected($this->label(), 'its response could not be read');
        }

        $code = is_array($decoded) ? ($decoded['code'] ?? null) : null;
        if ($code === 200) {
            return;
        }

        $message = is_array($decoded) && is_string($decoded['msg'] ?? null) ? $decoded['msg'] : '';

        throw NotifierException::rejected($this->label(), $this->explain(
            is_int($code) ? $code : 0,
            $message,
        ));
    }

    /**
     * Pushplus' `msg` is usually Chinese prose. The two codes a user can
     * actually act on are given in English; anything else is passed through,
     * because its own message beats a number.
     */
    private function explain(int $code, string $message): string
    {
        return match ($code) {
            401 => 'the token was not accepted',
            403 => 'that token is not allowed to send — check it has not expired',
            default => $message === '' ? 'it reported code ' . $code : $message,
        };
    }
}
