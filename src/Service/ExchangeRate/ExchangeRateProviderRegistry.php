<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

/**
 * The list of available providers, in the order they are offered.
 *
 * The wizard, the settings page and the refresh command all read this, so a
 * new provider becomes selectable everywhere by being added to the container
 * definition — no call site changes.
 */
final class ExchangeRateProviderRegistry
{
    /** @var array<string, ExchangeRateProvider> */
    private array $providers = [];

    /**
     * @param list<ExchangeRateProvider> $providers The first is the default.
     */
    public function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /**
     * @return list<ExchangeRateProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): ?ExchangeRateProvider
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * The provider used when the instance has expressed no preference.
     *
     * It is the free, keyless one by construction: an instance that has not
     * been configured still converts currencies correctly.
     */
    public function default(): ExchangeRateProvider
    {
        $first = $this->all()[0] ?? null;
        if ($first === null) {
            throw new \LogicException('No exchange-rate provider is registered.');
        }

        return $first;
    }

    /**
     * The configured provider, falling back to the default when the stored key
     * names a provider that no longer exists.
     */
    public function resolve(?string $key): ExchangeRateProvider
    {
        return ($key !== null ? $this->get($key) : null) ?? $this->default();
    }
}
