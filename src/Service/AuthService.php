<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\User;
use App\Domain\Role;
use App\Repository\AuthAttemptRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Sign-up, sign-in, and email verification.
 *
 * Controllers hold no auth logic: they collect input, call one of these
 * methods, and render whatever comes back. A later API phase will call exactly
 * the same methods.
 */
final class AuthService
{
    public const MIN_PASSWORD_LENGTH = 10;

    private const VERIFICATION_TOKEN_TTL = '+2 days';

    public function __construct(
        private readonly UserRepository $users,
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly TokenRepository $tokens,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $rateLimiter,
        private readonly MailerService $mailer,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Register a new account together with its own household.
     *
     * @throws ValidationException
     */
    public function register(string $email, string $displayName, string $password, string $passwordConfirm): User
    {
        $errors = [];

        $email = $this->users->normaliseEmail($email);
        $displayName = trim($displayName);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = 'An account with that email address already exists.';
        }

        if ($displayName === '') {
            $errors['display_name'] = 'Enter a name.';
        } elseif (mb_strlen($displayName) > 100) {
            $errors['display_name'] = 'Name must be 100 characters or fewer.';
        }

        $errors += $this->validatePassword($password, $passwordConfirm);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $userId = $this->users->create($email, $displayName, $this->hasher->hash($password));

        $householdId = $this->households->create(sprintf("%s's household", $displayName), $userId);
        $this->memberships->create($householdId, $userId, Role::OwnerAdmin);

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('The account could not be read back after creation.');
        }

        $this->sendVerificationEmail($user);

        return $user;
    }

    public function sendVerificationEmail(User $user): void
    {
        $token = $this->tokens->issue(
            $user->id,
            TokenRepository::PURPOSE_VERIFY_EMAIL,
            $this->clock->now()->modify(self::VERIFICATION_TOKEN_TTL),
        );

        $link = rtrim($this->appUrl, '/') . '/verify-email?token=' . urlencode($token);

        $this->mailer->send(
            $user->email,
            $user->displayName,
            sprintf('Confirm your %s account', $this->settings->instanceName()),
            <<<TEXT
            Hello {$user->displayName},

            Confirm your email address to finish setting up your account:

            {$link}

            The link is valid for two days. If you did not create an account,
            you can ignore this message.
            TEXT,
        );
    }

    /**
     * @return bool True when the token was valid and the address is now verified.
     */
    public function verifyEmail(string $token): bool
    {
        $userId = $this->tokens->findValidUserId($token, TokenRepository::PURPOSE_VERIFY_EMAIL);
        if ($userId === null) {
            return false;
        }

        if (!$this->tokens->consume($token, TokenRepository::PURPOSE_VERIFY_EMAIL)) {
            return false;
        }

        $this->users->markEmailVerified($userId, $this->clock->now());

        return true;
    }

    /**
     * Attempt a login.
     *
     * Failures are deliberately indistinguishable from one another — a wrong
     * password and an unknown address produce the same message and comparable
     * timing — so the form cannot be used to enumerate accounts. The one
     * exception is an unverified address, which the user needs to be told
     * about, and which is only reachable after the correct password.
     *
     * @throws ValidationException
     */
    public function attemptLogin(string $email, string $password, string $ipAddress): User
    {
        $email = $this->users->normaliseEmail($email);

        $remaining = $this->rateLimiter->remainingLockoutSeconds(
            AuthAttemptRepository::KIND_LOGIN,
            $email,
            $ipAddress,
        );

        if ($remaining > 0) {
            throw ValidationException::field('email', sprintf(
                'Too many attempts. Try again in %d minutes.',
                max(1, (int) ceil($remaining / 60)),
            ));
        }

        $user = $this->users->findByEmail($email);
        $verified = $user !== null && $this->hasher->verify($password, $user->passwordHash);

        if ($user === null || !$verified) {
            // Spend the hashing time even when the account does not exist.
            if ($user === null) {
                $this->hasher->verify($password, '');
            }

            $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, $email, $ipAddress);

            throw ValidationException::field('email', 'Those credentials are not correct.');
        }

        if (!$user->isVerified()) {
            $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, $email, $ipAddress);

            throw ValidationException::field(
                'email',
                'Confirm your email address before signing in. Check your inbox for the link.',
            );
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            // The user has just proved the password, so this is the one moment
            // an old hash can be upgraded transparently.
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->rateLimiter->recordSuccess(AuthAttemptRepository::KIND_LOGIN, $email, $ipAddress);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    public function validatePassword(string $password, string $confirm): array
    {
        $errors = [];

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = sprintf(
                'Use at least %d characters.',
                self::MIN_PASSWORD_LENGTH,
            );
        } elseif (mb_strlen($password) > 4096) {
            // Cap the input so an enormous string cannot be used to burn CPU
            // in the hashing function.
            $errors['password'] = 'That password is too long.';
        }

        if ($password !== $confirm) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }

        return $errors;
    }
}
