<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\Entity\User;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Support\Clock;
use RuntimeException;

/**
 * The first-run wizard.
 *
 * Creates the bootstrap account — the only one that is an instance admin by
 * default — along with its household, and records the two decisions that are
 * awkward to change later: the base currency and the data-isolation mode.
 *
 * The wizard closes itself permanently once it has run. It is guarded twice:
 * by the completion flag, and by there being no users yet. Either one alone
 * would be enough; both together mean a half-finished first run cannot leave
 * the instance open to a second, hostile one.
 */
final class SetupService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly InstanceSettingsService $settings,
        private readonly ExchangeRateProviderRegistry $rateProviders,
        private readonly PasswordHasher $hasher,
        private readonly AuthService $auth,
        private readonly Clock $clock,
        private readonly string $environmentApiKey = '',
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
        $householdName = trim($this->str($input, 'household_name'));
        $instanceName = trim($this->str($input, 'instance_name'));
        $password = $this->str($input, 'password');
        $confirm = $this->str($input, 'password_confirm');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($displayName === '') {
            $errors['display_name'] = 'Enter your name.';
        }
        if ($householdName === '') {
            $householdName = sprintf("%s's household", $displayName === '' ? 'My' : $displayName);
        }
        if ($instanceName === '') {
            $instanceName = 'Renovo';
        }

        $currency = Currency::normalise($this->str($input, 'base_currency'));
        if (!Currency::isValidCode($currency)) {
            $errors['base_currency'] = 'Choose a base currency.';
        }

        $isolation = IsolationMode::tryFrom($this->str($input, 'isolation_mode'));
        if ($isolation === null) {
            $errors['isolation_mode'] = 'Choose how data is shared.';
        }

        // An unrecognised provider key falls back to the default rather than
        // failing the whole wizard: rates are a convenience, and refusing to
        // create the administrator account over one would be disproportionate.
        $rateProvider = $this->rateProviders->resolve($this->str($input, 'rate_provider'));
        $rateProviderKey = trim($this->str($input, 'rate_provider_key'));

        if ($rateProvider->requiresApiKey() && $rateProviderKey === '' && $this->environmentApiKey === '') {
            $errors['rate_provider_key'] = sprintf('%s needs an API key.', $rateProvider->label());
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

        $householdId = $this->households->create($householdName, $userId);
        $this->memberships->create($householdId, $userId, Role::OwnerAdmin);

        $this->settings->setBaseCurrency($currency);
        $this->settings->setRateProvider($rateProvider->key());
        if ($rateProviderKey !== '') {
            $this->settings->setRateProviderKey($rateProviderKey);
        }
        $this->settings->setIsolationMode($isolation ?? IsolationMode::Shared);
        $this->settings->setInstanceName($instanceName);
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
