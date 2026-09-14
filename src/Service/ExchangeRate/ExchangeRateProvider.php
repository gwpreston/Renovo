<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

/**
 * A source of exchange rates.
 *
 * Adding one means implementing this interface and registering it in
 * ExchangeRateProviderRegistry. Nothing else changes: the cache, the
 * conversion arithmetic, the wizard's pick-list and the settings form all read
 * the registry.
 *
 * Every implementation fetches through the shared HTTP client, and every
 * implementation's endpoint is a constant in its own class. A base URL is
 * never read from the database, which is what keeps this phase free of
 * user-supplied URLs — and therefore free of the SSRF surface that comes with
 * them. An operator who needs to point at a mirror does it with an environment
 * variable, which they already control completely.
 */
interface ExchangeRateProvider
{
    /**
     * Stable identifier, stored in settings and against each cached rate.
     */
    public function key(): string;

    public function label(): string;

    /**
     * A sentence for the wizard and the settings page.
     */
    public function description(): string;

    public function requiresApiKey(): bool;

    /**
     * Fetch the provider's current table.
     *
     * Implementations return whatever base the provider actually publishes —
     * some free tiers only offer one — and the service derives the pairs it
     * needs by cross-rating. Asking for a base a provider cannot serve and
     * getting silently wrong numbers back is the failure this avoids.
     *
     * @param string      $preferredBase Base the caller would like, if supported.
     * @param string|null $apiKey        Resolved key, or null when none is configured.
     * @throws RateProviderException on transport failure or an unusable response.
     */
    public function fetch(string $preferredBase, ?string $apiKey): RateTable;
}
