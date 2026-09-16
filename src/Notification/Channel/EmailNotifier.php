<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\User;
use App\Notification\Alert;
use App\Notification\ChannelField;
use App\Notification\Notifier;
use App\Notification\NotifierException;
use App\Service\MailerService;
use App\Service\ValidationException;

/**
 * Notifications by email, over the instance's configured SMTP relay.
 *
 * The one channel exempt from the SSRF rules, and worth saying why rather than
 * leaving it to be inferred: the guard exists because a user can type a URL and
 * make the server fetch it. Nobody types the SMTP host — it comes from the
 * operator's environment, the same as the database credentials — so there is no
 * user-controlled destination to protect against. What the user supplies here
 * is an address, and an address is delivered to by the relay, not connected to
 * by us.
 *
 * The address defaults to the account's own, because that is what almost
 * everybody wants and typing it again is an opportunity to get it wrong.
 */
final class EmailNotifier implements Notifier
{
    public function __construct(private readonly MailerService $mailer)
    {
    }

    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function fields(): array
    {
        return [
            new ChannelField(
                'address',
                'channel_field.email.address',
                'email',
                false,
                'channel_field.email.address_hint',
            ),
        ];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        $address = trim($input['address'] ?? '');

        if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::field('address', 'error.email.invalid');
        }

        return ['address' => $address];
    }

    public function describe(array $config): string
    {
        $address = trim($config['address'] ?? '');

        return $address === '' ? 'Your account email address' : $address;
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        $address = $channel->config('address', $recipient->email);

        $body = $alert->body();
        if ($alert->url !== null) {
            $body .= "\n\n" . $alert->url;
        }

        if (!$this->mailer->send($address, $recipient->displayName, $alert->title, $body)) {
            // MailerService logs the detail and reports a boolean; a false here
            // is a delivery failure, and the ledger must record it as one so
            // the next run tries again.
            throw NotifierException::transport($this->label(), 'the mail relay refused the message');
        }
    }
}
