<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Captures outbound mail instead of sending it, so a test can read the
 * verification or reset link out of the message body.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
    }

    public function lastBody(): string
    {
        $message = end($this->messages);
        if ($message === false) {
            return '';
        }

        // Mailer encodes the body as quoted-printable, which wraps long lines
        // with soft breaks — enough to split a hex token in half. Decode
        // before reading anything out of it.
        return quoted_printable_decode($message->toString());
    }

    /**
     * Pull a token out of the most recent message's links.
     */
    public function lastToken(): ?string
    {
        if (preg_match('/token=([A-Za-z0-9%]+)/', $this->lastBody(), $matches) === 1) {
            return urldecode($matches[1]);
        }

        return null;
    }

    public function reset(): void
    {
        $this->messages = [];
    }
}
