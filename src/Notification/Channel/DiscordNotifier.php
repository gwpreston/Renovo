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
 * Messages to a Discord channel through an incoming webhook.
 *
 * **The webhook URL is the credential.** There is no separate token field
 * because Discord does not issue one: anybody holding
 * `discord.com/api/webhooks/<id>/<token>` can post to that channel. That single
 * fact decides three things below — `describe()` returns the host rather than
 * the URL, `redact()` strips the path out of transport errors before they are
 * stored against the channel, and the field is validated for the webhook shape
 * so a user who pastes the channel's ordinary URL is told so immediately.
 *
 * Discord is a public service on https and there is no self-hosted variant, so
 * the send is https-forced and no allowlist entry can downgrade it.
 */
final class DiscordNotifier extends HttpNotifier implements Notifier
{
    /**
     * Discord truncates at 2000 characters and rejects nothing, so a long
     * alert would arrive silently cut. Trimming here keeps the tail we choose
     * rather than the tail Discord chooses.
     */
    private const MAX_CONTENT = 2000;

    public function key(): string
    {
        return 'discord';
    }

    public function label(): string
    {
        return 'Discord';
    }

    public function fields(): array
    {
        // `secret`, not `url`: the settings form re-renders an ordinary field's
        // stored value into the page, and this field's value *is* the
        // credential. Gotify and the generic webhook can use `url` because
        // their secret is a separate field and the URL is only an address.
        return [
            ChannelField::secret('url', 'channel_field.discord.url', 'channel_field.discord.url_hint'),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        // Blank means "unchanged" — and it has to be resolved before the URL is
        // validated, or editing just the channel's name would fail on a field
        // the form deliberately did not show.
        $url = trim($input['url'] ?? '');
        if ($url === '') {
            $url = trim($existing['url'] ?? '');
        }

        $url = $this->validateUrl('url', $url);

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw ValidationException::field('url', 'error.discord.https_required');
        }

        // The shape check that catches the common mistake: pasting the Discord
        // channel's own address instead of the webhook's.
        if (!str_contains((string) parse_url($url, PHP_URL_PATH), '/api/webhooks/')) {
            throw ValidationException::field('url', 'error.discord.url_invalid');
        }

        return ['url' => $url];
    }

    public function describe(array $config): string
    {
        return $this->summariseUrl($config['url'] ?? '');
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $url = $channel->config('url');
        if ($url === '') {
            throw NotifierException::misconfigured($this->label(), 'no webhook URL is set');
        }

        $content = '**' . $alert->title . '**';
        if ($alert->lines !== []) {
            $content .= "\n" . $alert->body();
        }
        if ($alert->url !== null) {
            $content .= "\n" . $alert->url;
        }

        $response = $this->postJson(
            $url,
            ['content' => mb_substr($content, 0, self::MAX_CONTENT)],
            [],
            true,
        );

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw NotifierException::rejected($this->label(), match ($status) {
            401, 403 => 'the webhook token was not accepted',
            404 => 'that webhook no longer exists — it may have been deleted in Discord',
            429 => 'it is rate-limiting this webhook',
            default => $this->explain($response->getBody()->__toString(), $status),
        });
    }

    /**
     * Discord answers a rejected payload with `{"message": "...", "code": N}`.
     * Its own words are more useful than a status code, when there are any.
     */
    private function explain(string $body, int $status): string
    {
        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'it answered HTTP ' . $status;
        }

        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : '';

        return $message === '' ? 'it answered HTTP ' . $status : $message;
    }

    protected function redact(string $message): string
    {
        // `HttpClientException` names the URL it failed on, and that URL holds
        // the webhook token. Without this, one DNS failure would write a live
        // credential into `last_error` and onto the settings page.
        return (string) preg_replace('#(/api/webhooks/)\S*#i', '$1...', $message);
    }
}
