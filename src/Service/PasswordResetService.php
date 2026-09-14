<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuthAttemptRepository;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
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
            throw ValidationException::field('email', sprintf(
                'Too many reset requests. Try again in %d minutes.',
                max(1, (int) ceil($remaining / 60)),
            ));
        }

        // Every request counts against the limit, successful or not: counting
        // only the misses would let an attacker enumerate addresses by
        // watching which ones fail to throttle.
        $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_RESET, $email, $ipAddress);

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return;
        }

        $token = $this->tokens->issue(
            $user->id,
            TokenRepository::PURPOSE_RESET_PASSWORD,
            $this->clock->now()->modify(self::TOKEN_TTL),
        );

        $link = rtrim($this->appUrl, '/') . '/reset-password?token=' . urlencode($token);

        $this->mailer->send(
            $user->email,
            $user->displayName,
            sprintf('Reset your %s password', $this->settings->instanceName()),
            <<<TEXT
            Hello {$user->displayName},

            Someone asked to reset the password for this account. If it was
            you, follow this link within the next hour:

            {$link}

            If it was not you, no action is needed — the password has not
            changed.
            TEXT,
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
            throw ValidationException::field('token', 'That reset link has expired or has already been used.');
        }

        $this->users->updatePasswordHash($userId, $this->hasher->hash($password));

        $user = $this->users->findById($userId);
        if ($user !== null) {
            // A completed reset clears the account's failure history for both
            // login and reset: the person who owns the mailbox has proved
            // control, and leaving them locked out would be the wrong outcome.
            $this->attempts->clearForAccount(AuthAttemptRepository::KIND_RESET, $user->email);
            $this->attempts->clearForAccount(AuthAttemptRepository::KIND_LOGIN, $user->email);

            $this->mailer->send(
                $user->email,
                $user->displayName,
                sprintf('Your %s password was changed', $this->settings->instanceName()),
                <<<TEXT
                Hello {$user->displayName},

                Your password has just been changed. If this was not you,
                contact the administrator of this instance immediately.
                TEXT,
            );
        }
    }
}
