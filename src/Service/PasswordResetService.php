<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Locales;
use App\I18n\Translator;
use App\Domain\AuditAction;
use App\Repository\AuthAttemptRepository;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\SessionInterface;
use App\Service\Auth\SessionDirectoryService;
use App\Support\Clock;

/**
 * Password reset by emailed single-use token.
 *
 * `request()` returns nothing and never reveals whether the address exists:
 * the response the user sees is identical either way, which is the only thing
 * that stops the reset form doubling as an account-enumeration oracle.
 */
final class PasswordResetService
{
    private const TOKEN_TTL = '+1 hour';

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly AuthAttemptRepository $attempts,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $rateLimiter,
        private readonly MailerService $mailer,
        private readonly AuthService $auth,
        private readonly InstanceSettingsService $settings,
        private readonly Translator $translator,
        private readonly Locales $locales,
        private readonly AuditLogService $audit,
        private readonly SessionDirectoryService $sessions,
        private readonly SessionInterface $session,
        private readonly Clock $clock,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @throws ValidationException when the request is being throttled.
     */
    public function request(string $email, string $ipAddress): void
    {
        $email = $this->users->normaliseEmail($email);

        $remaining = $this->rateLimiter->remainingLockoutSeconds(
            AuthAttemptRepository::KIND_RESET,
            $email,
            $ipAddress,
        );

        if ($remaining > 0) {
            throw ValidationException::field(
                'email',
                'error.reset.throttled',
                ['minutes' => max(1, (int) ceil($remaining / 60))],
            );
        }

        // Every request counts against the limit, successful or not: counting
        // only the misses would let an attacker enumerate addresses by
        // watching which ones fail to throttle.
        $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_RESET, $email, $ipAddress);

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            // Still recorded: a run of reset requests for addresses that do not
            // exist is exactly the pattern an administrator wants to see.
            $this->audit->recordAnonymous(AuditAction::PasswordResetRequested, $email, null, [
                'account_exists' => false,
            ]);

            return;
        }

        $this->audit->recordAnonymous(AuditAction::PasswordResetRequested, $email, $user);

        $token = $this->tokens->issue(
            $user->id,
            TokenRepository::PURPOSE_RESET_PASSWORD,
            $this->clock->now()->modify(self::TOKEN_TTL),
        );

        $link = rtrim($this->appUrl, '/') . '/reset-password?token=' . urlencode($token);

        $locale = $this->locales->resolve($user->locale);

        $this->mailer->send(
            $user->email,
            $user->displayName,
            $this->translator->trans('mail.reset.subject', ['instance' => $this->settings->instanceName()], $locale),
            $this->translator->trans('mail.reset.body', ['name' => $user->displayName, 'link' => $link], $locale),
        );
    }

    public function isTokenValid(string $token): bool
    {
        return $this->tokens->findValidUserId($token, TokenRepository::PURPOSE_RESET_PASSWORD) !== null;
    }

    /**
     * @throws ValidationException
     */
    public function reset(string $token, string $password, string $passwordConfirm): void
    {
        $errors = $this->auth->validatePassword($password, $passwordConfirm);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $userId = $this->tokens->findValidUserId($token, TokenRepository::PURPOSE_RESET_PASSWORD);
        if ($userId === null || !$this->tokens->consume($token, TokenRepository::PURPOSE_RESET_PASSWORD)) {
            throw ValidationException::field('token', 'error.reset.invalid_token');
        }

        $this->users->updatePasswordHash($userId, $this->hasher->hash($password));

        $user = $this->users->findById($userId);
        if ($user !== null) {
            // A completed reset clears the account's failure history for both
            // login and reset: the person who owns the mailbox has proved
            // control, and leaving them locked out would be the wrong outcome.
            $this->attempts->clearForAccount(AuthAttemptRepository::KIND_RESET, $user->email);
            $this->attempts->clearForAccount(AuthAttemptRepository::KIND_LOGIN, $user->email);

            // Every session this account had is now suspect: the reset may well
            // have been prompted by somebody else having one. The request doing
            // the reset is not signed in, so nothing is spared.
            $revoked = $this->sessions->revokeAllOthersSilently($user->id, $this->session->id());

            $this->audit->record(AuditAction::PasswordChanged, $user, [
                'via' => 'reset_link',
                'sessions_revoked' => $revoked,
            ]);

            $locale = $this->locales->resolve($user->locale);

            $this->mailer->send(
                $user->email,
                $user->displayName,
                $this->translator->trans(
                    'mail.password_changed.subject',
                    ['instance' => $this->settings->instanceName()],
                    $locale,
                ),
                $this->translator->trans(
                    'mail.password_changed.body',
                    ['name' => $user->displayName],
                    $locale,
                ),
            );
        }
    }
}
