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

/**
 * Publish to an ntfy topic, on `ntfy.sh` or on a self-hosted server.
 *
 * **This is the one channel whose transport rule is computed rather than
 * fixed.** Every other channel here is decided at author time: Slack, Discord,
 * Telegram and the rest are public services with no self-hosted variant, so
 * https is forced; Gotify and Mattermost are self-hosted, so the allowlist
 * decides. ntfy is both, and which one it is depends on the URL the user
 * typed. So the rule is: the public host is https-forced and no allowlist entry
 * can downgrade it, and anything else is treated exactly as a self-hosted
 * Gotify — reachable on a private address, or on http, only if an
 * administrator has trusted it. `httpsOnly()` is that decision, and it is
 * tested on both sides.
 *
 * The message is published as JSON to the server root with the topic in the
 * body, rather than as a plain body POSTed to `<server>/<topic>`. ntfy supports
 * both, but the second puts the title in an `X-Title` header, and headers are
 * latin-1: a subscription named "Café" or a Japanese title would arrive
 * mangled or be rejected outright. The JSON body is UTF-8 and has no such
 * problem.
 */
final class NtfyNotifier extends HttpNotifier implements Notifier
{
    public const PUBLIC_HOST = 'ntfy.sh';

    private const DEFAULT_SERVER = 'https://ntfy.sh';

    public function key(): string
    {
        return 'ntfy';
    }

    public function label(): string
    {
        return 'ntfy';
    }

    public function fields(): array
    {
        return [
            new ChannelField('server', 'channel_field.ntfy.server', 'url', false, 'channel_field.ntfy.server_hint'),
            new ChannelField('topic', 'channel_field.ntfy.topic', 'text', true, 'channel_field.ntfy.topic_hint'),
            new ChannelField(
                'token',
                'channel_field.ntfy.token',
                'password',
                false,
                'channel_field.ntfy.token_hint',
                true,
            ),
            new ChannelField(
                'priority',
                'channel_field.ntfy.priority',
                'number',
                false,
                'channel_field.ntfy.priority_hint',
            ),
            ChannelField::optional('tags', 'channel_field.ntfy.tags', 'channel_field.ntfy.tags_hint'),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        $server = trim($input['server'] ?? '');
        $server = $server === '' ? self::DEFAULT_SERVER : $this->validateUrl('server', $server);

        $topic = trim($input['topic'] ?? '');
        if ($topic === '') {
            throw ValidationException::field('topic', 'error.ntfy.topic_required');
        }

        // ntfy's own rule for a topic name.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $topic) !== 1) {
            throw ValidationException::field('topic', 'error.ntfy.topic_invalid');
        }

        // Optional: a public topic needs no token at all. Blank on an existing
        // channel keeps whatever is stored, like every other secret here.
        $token = trim($input['token'] ?? '');
        if ($token === '') {
            $token = trim($existing['token'] ?? '');
        }

        $priority = trim($input['priority'] ?? '');
        if ($priority !== '' && (!ctype_digit($priority) || (int) $priority < 1 || (int) $priority > 5)) {
            throw ValidationException::field('priority', 'error.ntfy.priority_range');
        }

        $tags = trim($input['tags'] ?? '');
        if ($tags !== '' && preg_match('/^[A-Za-z0-9_,+-]{1,200}$/', $tags) !== 1) {
            throw ValidationException::field('tags', 'error.ntfy.tags_invalid');
        }

        return [
            'server' => $server,
            'topic' => $topic,
            'token' => $token,
            'priority' => $priority === '' ? '3' : $priority,
            'tags' => $tags,
        ];
    }

    public function describe(array $config): string
    {
        $server = $config['server'] ?? self::DEFAULT_SERVER;
        $topic = $config['topic'] ?? '';

        // Unlike the webhook channels, an ntfy URL holds no secret — the token
        // is a separate field and travels in a header — so the full
        // server-plus-topic is safe to show, and is what the user needs to see.
        return rtrim($server, '/') . '/' . $topic;
    }

    /**
     * https is forced for the public service and negotiable for a self-hosted
     * one. See the class comment; this is the phase's one computed transport
     * rule.
     */
    public function httpsOnly(string $server): bool
    {
        $host = strtolower((string) parse_url($server, PHP_URL_HOST));

        return $host === self::PUBLIC_HOST || str_ends_with($host, '.' . self::PUBLIC_HOST);
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $server = $channel->config('server', self::DEFAULT_SERVER);
        $topic = $channel->config('topic');

        if ($topic === '') {
            throw NotifierException::misconfigured($this->label(), 'no topic is set');
        }

        $message = $alert->body();
        $payload = [
            'topic' => $topic,
            'title' => $alert->title,
            'message' => $message === '' ? $alert->title : $message,
            'priority' => (int) $channel->config('priority', '3'),
        ];

        if ($alert->url !== null) {
            // `click` is what ntfy opens when the notification is tapped.
            $payload['click'] = $alert->url;
        }

        if ($channel->hasConfig('tags')) {
            // The JSON schema takes a string array, not the comma-separated
            // string the `X-Tags` header form uses.
            $payload['tags'] = array_values(array_filter(
                array_map('trim', explode(',', $channel->config('tags'))),
                static fn (string $tag): bool => $tag !== '',
            ));
        }

        $headers = [];
        // `hasConfig`, not `config`: an empty token must mean no header at all,
        // not `Authorization: Bearer ` with nothing after it, which ntfy
        // rejects as malformed rather than treating as anonymous.
        if ($channel->hasConfig('token')) {
            $headers['Authorization'] = 'Bearer ' . $channel->config('token');
        }

        $response = $this->postJson(
            rtrim($server, '/'),
            $payload,
            $headers,
            $this->httpsOnly($server),
        );

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw NotifierException::rejected($this->label(), match ($status) {
            401 => 'the topic is protected and no access token was accepted',
            403 => 'that access token may not publish to this topic',
            404 => 'the server URL does not look like an ntfy server',
            429 => 'it is rate-limiting this topic',
            507 => 'the server is out of storage',
            default => 'it answered HTTP ' . $status,
        });
    }
}
