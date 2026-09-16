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
 * Push notifications through a self-hosted Gotify server.
 *
 * The token goes in the `X-Gotify-Key` header rather than the `?token=` query
 * parameter Gotify also accepts. Both authenticate; only one keeps the secret
 * out of URLs, and URLs are the part of a request that ends up in access logs,
 * in error messages and in whatever a proxy decides to record.
 *
 * A Gotify server is very often on a LAN or a Tailscale address, and very often
 * on plain http. That combination is refused by default and is exactly what the
 * administrator's trusted-host list re-opens — which is why this channel does
 * not force https: a host an administrator has deliberately trusted may be
 * reached over http, and everything else may not.
 */
final class GotifyNotifier extends HttpNotifier implements Notifier
{
    public function key(): string
    {
        return 'gotify';
    }

    public function label(): string
    {
        return 'Gotify';
    }

    public function fields(): array
    {
        return [
            ChannelField::url('url', 'channel_field.gotify.url', 'channel_field.gotify.url_hint'),
            ChannelField::secret('token', 'channel_field.gotify.token', 'channel_field.gotify.token_hint'),
            new ChannelField(
                'priority',
                'channel_field.gotify.priority',
                'number',
                false,
                'channel_field.gotify.priority_hint',
            ),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        $url = $this->validateUrl('url', $input['url'] ?? '');

        // A blank token on an existing channel means "leave it alone": the form
        // never shows the stored value, so it cannot ask the user to retype it.
        $token = trim($input['token'] ?? '');
        if ($token === '') {
            $token = trim($existing['token'] ?? '');
        }

        if ($token === '') {
            throw ValidationException::field('token', 'error.gotify.token_required');
        }

        $priority = trim($input['priority'] ?? '');
        if ($priority !== '' && (!ctype_digit($priority) || (int) $priority > 10)) {
            throw ValidationException::field('priority', 'error.gotify.priority_range');
        }

        return [
            'url' => $url,
            'token' => $token,
            'priority' => $priority === '' ? '5' : $priority,
        ];
    }

    public function describe(array $config): string
    {
        return $config['url'] ?? '';
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $url = $channel->config('url');
        if ($url === '') {
            throw NotifierException::misconfigured($this->label(), 'no server URL is set');
        }

        $body = $alert->body();
        if ($alert->url !== null) {
            $body .= "\n\n" . $alert->url;
        }

        $response = $this->postJson(
            rtrim($url, '/') . '/message',
            [
                'title' => $alert->title,
                'message' => $body,
                'priority' => (int) $channel->config('priority', '5'),
            ],
            ['X-Gotify-Key' => $channel->config('token')],
        );

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw NotifierException::rejected($this->label(), match ($status) {
                401, 403 => 'the application token was not accepted',
                404 => 'the server URL does not look like a Gotify server',
                default => 'it answered HTTP ' . $status,
            });
        }
    }
}
