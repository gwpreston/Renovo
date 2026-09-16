<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\User;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Support\Clock;
use RuntimeException;

/**
 * The first-run wizard.
 *
 * Creates the bootstrap account — the only one that is an instance admin by
 * default — along with its household. Nothing else is asked for: currency,
 * isolation mode, rate provider and the household's name all have working
 * defaults and are editable from settings once the administrator is in, so
 * making them a barrier to the first sign-in buys nothing.
 *
 * The wizard closes itself permanently once it has run. It is guarded twice:
 * by the completion flag, and by there being no users yet. Either one alone
 * would be enough; both together mean a half-finished first run cannot leave
 * the instance open to a second, hostile one.
 */
final class SetupService
{
    private const DEFAULT_HOUSEHOLD_NAME = 'Home';

    public function __construct(
        private readonly UserRepository $users,
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly InstanceSettingsService $settings,
        private readonly PasswordHasher $hasher,
        private readonly AuthService $auth,
        private readonly Clock $clock,
    ) {
    }

    public function isRequired(): bool
    {
        return !$this->settings->isSetupComplete() && $this->users->countAll() === 0;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function complete(array $input): User
    {
        if (!$this->isRequired()) {
            throw new RuntimeException('This instance has already been set up.');
        }

        $errors = [];

        $email = $this->users->normaliseEmail($this->str($input, 'email'));
        $displayName = trim($this->str($input, 'display_name'));
        $password = $this->str($input, 'password');
        $confirm = $this->str($input, 'password_confirm');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'error.email.invalid';
        }
        if ($displayName === '') {
            $errors['display_name'] = 'error.name.required_yours';
        }

        $errors += $this->auth->validatePassword($password, $confirm);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // The bootstrap account is created already verified: there is nobody
        // to send a confirmation link to yet, and locking the only
        // administrator out behind an email that may not be deliverable would
        // make the instance unusable.
        $userId = $this->users->create(
            $email,
            $displayName,
            $this->hasher->hash($password),
            true,
            $this->clock->now(),
        );

        // A name, not a prompt. One household is the common case and "Home"
        // describes it; an owner who wants something else renames it in
        // settings, which is the same two clicks either way.
        $householdId = $this->households->create(self::DEFAULT_HOUSEHOLD_NAME, $userId);
        $this->memberships->create($householdId, $userId, Role::OwnerAdmin);

        $this->settings->markSetupComplete($this->clock->now()->format('Y-m-d H:i:s'));

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('The administrator account could not be read back after creation.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
