<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Outbound transactional mail.
 *
 * SMTP is deliberately not routed through the shared HTTP client: it is not an
 * HTTP fetch of a user-supplied URL, and the client's protections do not apply
 * to it. Its host comes from configuration, not from user input.
 */
final class MailerService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function send(string $toAddress, string $toName, string $subject, string $body): bool
    {
        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($toAddress, $toName))
            ->subject($subject)
            ->text($body);

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $exception) {
            // A failed verification email must not take the signup down with
            // it; the user can request another one.
            $this->logger->error('Failed to send mail', [
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
