<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Currency;
use App\Domain\ExchangeRate;
use DateTimeImmutable;

/**
 * The cached rate table.
 *
 * Unscoped on purpose, and it is worth saying why rather than leaving it as an
 * omission: exchange rates are public reference data. They belong to no
 * household, disclose nothing about anybody, and are identical for every user
 * of the instance. Putting them behind the scoping layer would mean either a
 * household id that means nothing or one table per household holding the same
 * numbers.
 *
 * Rates are stored against one base — the instance's base currency — and every
 * other pair is derived from those by the service. One row per quote currency
 * keeps the refresh a single replace rather than an N² fan-out.
 */
final class ExchangeRateRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'exchange_rates';
    }

    protected function filterableColumns(): array
    {
        return ['base_currency', 'quote_currency', 'provider', 'fetched_at'];
    }

    /**
     * Every cached rate for one base currency, keyed by quote currency.
     *
     * @return array<string, ExchangeRate>
     */
    public function findAllForBase(string $baseCurrency): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('exchange_rates')
            . ' WHERE ' . $this->quote('base_currency') . ' = :base',
            ['base' => Currency::normalise($baseCurrency)],
        );

        $rates = [];
        foreach ($rows as $row) {
            $quote = Currency::normalise((string) $row['quote_currency']);
            $rates[$quote] = ExchangeRate::of(
                (string) $row['base_currency'],
                $quote,
                (int) $row['rate_scaled'],
                $this->nullableDate($row['as_of_date'] ?? null),
                (string) $row['provider'],
            );
        }

        return $rates;
    }

    /**
     * When the base's rates were last written, or null if they never have been.
     */
    public function lastFetchedAt(string $baseCurrency): ?DateTimeImmutable
    {
        $value = $this->db->fetchValue(
            'SELECT MAX(' . $this->quote('fetched_at') . ') FROM ' . $this->quote('exchange_rates')
            . ' WHERE ' . $this->quote('base_currency') . ' = :base',
            ['base' => Currency::normalise($baseCurrency)],
        );

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * Replace one base's entire table.
     *
     * Delete-then-insert inside a transaction rather than an upsert: a currency
     * the provider has stopped publishing must disappear from the cache, not
     * linger at whatever it was worth the last time it was seen.
     *
     * @param list<ExchangeRate> $rates
     */
    public function replaceBase(string $baseCurrency, array $rates, DateTimeImmutable $fetchedAt): void
    {
        $base = Currency::normalise($baseCurrency);

        $this->db->transactional(function () use ($base, $rates, $fetchedAt): void {
            $this->db->execute(
                'DELETE FROM ' . $this->quote('exchange_rates')
                . ' WHERE ' . $this->quote('base_currency') . ' = :base',
                ['base' => $base],
            );

            foreach ($rates as $rate) {
                $this->db->insert('exchange_rates', [
                    'base_currency' => $base,
                    'quote_currency' => $rate->quoteCurrency,
                    'rate_scaled' => $rate->rateScaled,
                    'provider' => $rate->provider,
                    'as_of_date' => $rate->asOfDate?->format('Y-m-d'),
                    'fetched_at' => $fetchedAt->format('Y-m-d H:i:s'),
                ]);
            }
        });
    }

    /**
     * Drop everything. Used when the provider changes: rates from the previous
     * one are not wrong exactly, but they are not what the operator asked for.
     */
    public function clear(): void
    {
        $this->db->execute('DELETE FROM ' . $this->quote('exchange_rates'));
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
