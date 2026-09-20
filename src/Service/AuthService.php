<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Locales;
use App\I18n\Translator;
use App\Domain\AuditAction;
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
        private readonly Translator $translator,
        private readonly Locales $locales,
        private readonly AuditLogService $audit,
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
            $errors['email'] = 'error.email.invalid';
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = 'error.email.taken';
        }

        if ($displayName === '') {
            $errors['display_name'] = 'error.name.required';
        } elseif (mb_strlen($displayName) > 100) {
            $errors['display_name'] = 'error.name.too_long_100';
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

        // In the recipient's language, not the language of whoever triggered
        // the send: a message is read by the person it is addressed to.
        $locale = $this->locales->resolve($user->locale);

        $this->mailer->send(
            $user->email,
            $user->displayName,
            $this->translator->trans('mail.verify.subject', ['instance' => $this->settings->instanceName()], $locale),
            $this->translator->trans('mail.verify.body', ['name' => $user->displayName, 'link' => $link], $locale),
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

        $user = $this->users->findById($userId);
        if ($user !== null) {
            $this->audit->record(AuditAction::EmailVerified, $user);
        }

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
            $this->audit->recordAnonymous(AuditAction::LoginBlocked, $email, null, [
                'retry_in_seconds' => $remaining,
            ]);

            throw ValidationException::field(
                'email',
                'error.auth.throttled',
                ['minutes' => max(1, (int) ceil($remaining / 60))],
            );
        }

        $user = $this->users->findByEmail($email);
        $verified = $user !== null && $this->hasher->verify($password, $user->passwordHash);

        if ($user === null || !$verified) {
            // Spend the hashing time even when the account does not exist.
            if ($user === null) {
                $this->hasher->verify($password, '');
            }

            $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, $email, $ipAddress);

            $this->audit->recordAnonymous(AuditAction::LoginFailed, $email, $user, [
                'reason' => $user === null ? 'unknown_account' : 'bad_password',
            ]);

            throw ValidationException::field('email', 'error.auth.credentials');
        }

        if ($user->isDisabled()) {
            // Told plainly rather than folded into "wrong credentials". The
            // person has typed the right password into their own account and
            // an administrator has closed it; leaving them to guess at a
            // generic failure would have them resetting a password that was
            // never the problem. It discloses nothing an attacker could not
            // already learn, because reaching this line at all required the
            // correct password.
            $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, $email, $ipAddress);

            $this->audit->recordAnonymous(AuditAction::LoginFailed, $email, $user, ['reason' => 'disabled']);

            throw ValidationException::field('email', 'error.auth.disabled');
        }

        if (!$user->isVerified()) {
            $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, $email, $ipAddress);

            $this->audit->recordAnonymous(AuditAction::LoginFailed, $email, $user, ['reason' => 'unverified']);

            throw ValidationException::field(
                'email',
                'error.auth.unverified',
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
     * @return array<string, ValidationError|string>
     */
    public function validatePassword(string $password, string $confirm): array
    {
        $errors = [];

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = new ValidationError(
                'error.password.too_short',
                ['count' => self::MIN_PASSWORD_LENGTH],
            );
        } elseif (mb_strlen($password) > 4096) {
            // Cap the input so an enormous string cannot be used to burn CPU
            // in the hashing function.
            $errors['password'] = 'error.password.too_long';
        }

        if ($password !== $confirm) {
            $errors['password_confirm'] = 'error.password.mismatch';
        }

        return $errors;
    }
}
