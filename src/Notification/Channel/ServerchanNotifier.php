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
 * Messages to WeChat through Server酱 (Serverchan) Turbo.
 *
 * **This channel puts its secret in the URL, and has no choice.** The endpoint
 * is `sctapi.ftqq.com/<sendkey>.send`; there is no header form. Gotify's
 * docblock explains why that is normally avoided — URLs end up in access logs,
 * in error messages, and in whatever a proxy records — and none of that stops
 * being true here. What this class can control is its own side of it:
 *
 *  - `describe()` returns the service name, never the URL.
 *  - `redact()` strips the key out of transport errors, which otherwise name
 *    the failed URL and would store a live credential in
 *    `notification_channels.last_error` — a column rendered on the settings
 *    page.
 *
 * What it cannot control is the remote end's logs. That is the user's trade to
 * make in choosing the service, and the field hint says so.
 *
 * Form-encoded, per the API: `title` and `desp`. **`title` may not contain a
 * line break**, which the API rejects rather than trims.
 */
final class ServerchanNotifier extends HttpNotifier implements Notifier
{
    private const ENDPOINT = 'https://sctapi.ftqq.com/%s.send';

    public function key(): string
    {
        return 'serverchan';
    }

    public function label(): string
    {
        return 'Serverchan';
    }

    public function fields(): array
    {
        return [
            ChannelField::secret(
                'sendkey',
                'channel_field.serverchan.sendkey',
                'channel_field.serverchan.sendkey_hint',
            ),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        $sendkey = trim($input['sendkey'] ?? '');
        if ($sendkey === '') {
            $sendkey = trim($existing['sendkey'] ?? '');
        }

        if ($sendkey === '') {
            throw ValidationException::field('sendkey', 'error.serverchan.sendkey_required');
        }

        // A Turbo sendkey is `SCT` followed by digits and letters. The check is
        // strict because this value is interpolated into a URL path: anything
        // with a slash or a dot in it would build a request to somewhere else
        // entirely.
        if (preg_match('/^SCT[A-Za-z0-9]{5,60}$/', $sendkey) !== 1) {
            throw ValidationException::field('sendkey', 'error.serverchan.sendkey_invalid');
        }

        return ['sendkey' => $sendkey];
    }

    public function describe(array $config): string
    {
        // The sendkey is the only field, and it is both the credential and the
        // address. There is nothing here that can safely be shown.
        return 'Server酱';
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $sendkey = $channel->config('sendkey');
        if ($sendkey === '') {
            throw NotifierException::misconfigured($this->label(), 'no sendkey is set');
        }

        $body = $alert->body();
        if ($alert->url !== null) {
            $body .= "\n\n" . $alert->url;
        }

        $response = $this->postForm(
            sprintf(self::ENDPOINT, rawurlencode($sendkey)),
            [
                // The API rejects a title containing a newline.
                'title' => str_replace(["\r", "\n"], ' ', $alert->title),
                'desp' => $body === '' ? $alert->title : $body,
            ],
            [],
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

        // Success is `code: 0`, not a 2xx — the status alone would call a
        // rejected sendkey a delivery.
        if (is_array($decoded) && ($decoded['code'] ?? null) === 0) {
            return;
        }

        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : '';

        throw NotifierException::rejected($this->label(), $this->explain(
            $response->getStatusCode(),
            $message,
        ));
    }

    private function explain(int $status, string $message): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'the sendkey was not accepted',
            $status === 404 => 'that sendkey does not exist',
            $message !== '' => $message,
            default => 'it answered HTTP ' . $status,
        };
    }

    protected function redact(string $message): string
    {
        // The sendkey *is* the path. A transport failure names the URL, and
        // that message is stored and displayed.
        return (string) preg_replace('#/SCT[A-Za-z0-9]*\.send#i', '/....send', $message);
    }
}
