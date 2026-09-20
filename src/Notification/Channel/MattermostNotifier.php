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
 * Messages to a Mattermost channel through an incoming webhook.
 *
 * Mattermost is **usually self-hosted**, and a self-hosted Mattermost is very
 * often on a LAN, a Tailscale address, or plain http. So this channel follows
 * Gotify rather than Slack: https is *not* forced, and a private target is
 * reachable only if an administrator has put it on the trusted-host list. That
 * is the whole of the difference between the two kinds of channel in this
 * codebase, and getting it backwards here would make the common deployment —
 * Mattermost on the same LAN as Renovo — impossible to use.
 *
 * As with Discord, **the webhook URL is the credential**: Mattermost's own docs
 * say to treat it as sensitive. Hence the masked `describe()` and the
 * `redact()` below.
 */
final class MattermostNotifier extends HttpNotifier implements Notifier
{
    /** Mattermost splits anything longer across consecutive posts. */
    private const MAX_TEXT = 16383;

    public function key(): string
    {
        return 'mattermost';
    }

    public function label(): string
    {
        return 'Mattermost';
    }

    public function fields(): array
    {
        // `secret`, not `url`: Mattermost's own docs say to treat the hook URL
        // as sensitive, and an ordinary field is re-rendered into the page by
        // the settings form.
        return [
            ChannelField::secret('url', 'channel_field.mattermost.url', 'channel_field.mattermost.url_hint'),
            ChannelField::optional(
                'channel',
                'channel_field.mattermost.channel',
                'channel_field.mattermost.channel_hint',
            ),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        // Resolved before validation: blank means "keep what is stored", and
        // the form never showed it to begin with.
        $url = trim($input['url'] ?? '');
        if ($url === '') {
            $url = trim($existing['url'] ?? '');
        }

        $url = $this->validateUrl('url', $url);

        // The shape check that catches a pasted server address rather than a
        // webhook. Not a security check — the guard decides where a request may
        // actually go, at send time.
        if (!str_contains((string) parse_url($url, PHP_URL_PATH), '/hooks/')) {
            throw ValidationException::field('url', 'error.mattermost.url_invalid');
        }

        $channel = trim($input['channel'] ?? '');
        if ($channel !== '' && preg_match('/^@?[a-z0-9._-]{1,64}$/i', $channel) !== 1) {
            throw ValidationException::field('channel', 'error.mattermost.channel_invalid');
        }

        return ['url' => $url, 'channel' => $channel];
    }

    public function describe(array $config): string
    {
        $server = $this->summariseUrl($config['url'] ?? '');
        $channel = trim($config['channel'] ?? '');

        return $channel === '' ? $server : $server . ' · ' . $channel;
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $url = $channel->config('url');
        if ($url === '') {
            throw NotifierException::misconfigured($this->label(), 'no webhook URL is set');
        }

        $text = '**' . $alert->title . '**';
        if ($alert->lines !== []) {
            $text .= "\n" . $alert->body();
        }
        if ($alert->url !== null) {
            $text .= "\n" . $alert->url;
        }

        $payload = ['text' => mb_substr($text, 0, self::MAX_TEXT)];

        // Only sent when set: an empty `channel` would override the webhook's
        // own default with nothing and be rejected.
        if ($channel->hasConfig('channel')) {
            $payload['channel'] = $channel->config('channel');
        }

        // Note the absent `true`: a self-hosted Mattermost may legitimately be
        // on http behind the allowlist. See the class comment.
        $response = $this->postJson($url, $payload);

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw NotifierException::rejected($this->label(), match ($status) {
            400 => 'it rejected the message — the channel may not exist',
            401, 403 => 'that webhook was not accepted',
            404 => 'that webhook no longer exists, or the URL is not a Mattermost hook',
            default => 'it answered HTTP ' . $status,
        });
    }

    protected function redact(string $message): string
    {
        // The hook key is in the path, and a transport error names the path.
        return (string) preg_replace('#(/hooks/)\S*#i', '$1...', $message);
    }
}
