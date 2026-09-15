<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\User;
use App\Notification\Alert;
use App\Notification\ChannelField;
use App\Notification\Notifier;
use App\Notification\NotifierException;

/**
 * A JSON POST to whatever URL the user gives, for everything else.
 *
 * This is the escape hatch — ntfy, Home Assistant, a Discord relay, a script on
 * a NAS — and it is also the most dangerous channel in the application, because
 * the destination is entirely user-controlled. Everything it sends goes through
 * the guarded client, which is what keeps "notify me at this URL" from becoming
 * "fetch this internal address for me".
 *
 * The payload is a stable, documented shape rather than a rendered message, so
 * that whatever is at the other end can route on the alert type instead of
 * parsing English out of a string.
 *
 * An optional shared secret is signed over the body with HMAC-SHA256 and sent
 * as `X-Renovo-Signature`. It is what lets the receiver tell a genuine
 * notification from anybody who has learned the URL, which for a webhook is the
 * only authentication there is.
 */
final class WebhookNotifier extends HttpNotifier implements Notifier
{
    public function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Webhook';
    }

    public function fields(): array
    {
        return [
            ChannelField::url('url', 'Endpoint URL'),
            new ChannelField(
                'secret',
                'Shared secret',
                'password',
                false,
                'Optional. Sent as an HMAC-SHA256 signature of the body in X-Renovo-Signature.',
                true,
            ),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        $url = $this->validateUrl('url', $input['url'] ?? '');

        $secret = trim($input['secret'] ?? '');
        if ($secret === '') {
            // Blank means unchanged, as with every other secret field. Clearing
            // one is done by deleting the channel, which is unambiguous.
            $secret = trim($existing['secret'] ?? '');
        }

        return ['url' => $url, 'secret' => $secret];
    }

    public function describe(array $config): string
    {
        return $config['url'] ?? '';
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $url = $channel->config('url');
        if ($url === '') {
            throw NotifierException::misconfigured($this->label(), 'no endpoint URL is set');
        }

        $payload = [
            'type' => $alert->type->value,
            'title' => $alert->title,
            'message' => $alert->body(),
            'lines' => $alert->lines,
            'subject_type' => $alert->subjectType,
            'subject_id' => $alert->subjectId,
            'due_date' => $alert->dueDate?->format('Y-m-d'),
            'priority' => $alert->priority,
            'url' => $alert->url,
            'recipient' => $recipient->displayName,
        ];

        $headers = [];
        $secret = $channel->config('secret');
        if ($secret !== '') {
            $headers['X-Renovo-Signature'] = 'sha256=' . hash_hmac('sha256', $this->encode($payload), $secret);
        }

        $response = $this->postJson($url, $payload, $headers);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw NotifierException::rejected($this->label(), 'it answered HTTP ' . $status);
        }
    }
}
