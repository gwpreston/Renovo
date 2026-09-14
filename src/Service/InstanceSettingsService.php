<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\IsolationMode;
use App\Repository\InstanceSettingsRepository;

/**
 * Reads and writes the handful of instance-wide settings.
 *
 * Values are cached for the lifetime of the request: the isolation mode is
 * consulted on every scoped query, and it must not cost a round trip each
 * time.
 */
final class InstanceSettingsService
{
    public const KEY_ISOLATION_MODE = 'isolation_mode';
    public const KEY_BASE_CURRENCY = 'base_currency';
    public const KEY_SETUP_COMPLETED_AT = 'setup_completed_at';
    public const KEY_INSTANCE_NAME = 'instance_name';
    public const KEY_ALLOW_REGISTRATION = 'allow_registration';

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly InstanceSettingsRepository $repository)
    {
    }

    public function isolationMode(): IsolationMode
    {
        return IsolationMode::tryFrom($this->get(self::KEY_ISOLATION_MODE, IsolationMode::Shared->value))
            ?? IsolationMode::Shared;
    }

    public function setIsolationMode(IsolationMode $mode): void
    {
        $this->set(self::KEY_ISOLATION_MODE, $mode->value);
    }

    public function baseCurrency(): string
    {
        return Currency::normalise($this->get(self::KEY_BASE_CURRENCY, 'GBP'));
    }

    public function setBaseCurrency(string $currency): void
    {
        $this->set(self::KEY_BASE_CURRENCY, Currency::normalise($currency));
    }

    public function instanceName(): string
    {
        return $this->get(self::KEY_INSTANCE_NAME, 'Renovo');
    }

    public function setInstanceName(string $name): void
    {
        $this->set(self::KEY_INSTANCE_NAME, $name);
    }

    public function registrationAllowed(): bool
    {
        return $this->get(self::KEY_ALLOW_REGISTRATION, '1') === '1';
    }

    public function setRegistrationAllowed(bool $allowed): void
    {
        $this->set(self::KEY_ALLOW_REGISTRATION, $allowed ? '1' : '0');
    }

    public function isSetupComplete(): bool
    {
        return $this->get(self::KEY_SETUP_COMPLETED_AT, '') !== '';
    }

    public function markSetupComplete(string $timestamp): void
    {
        $this->set(self::KEY_SETUP_COMPLETED_AT, $timestamp);
    }

    private function get(string $key, string $default): string
    {
        $this->cache ??= $this->repository->all();

        $value = $this->cache[$key] ?? '';

        return $value === '' ? $default : $value;
    }

    private function set(string $key, string $value): void
    {
        $this->repository->set($key, $value);
        $this->cache[$key] = $value;
    }
}
