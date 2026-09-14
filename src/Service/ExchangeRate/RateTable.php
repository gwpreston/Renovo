<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

use App\Domain\Currency;
use App\Domain\ExchangeRate;
use App\Domain\Rounding;
use DateTimeImmutable;

/**
 * One provider response: every quote currency expressed against a single base.
 *
 * Rates are held as integers scaled by ExchangeRate::SCALE. The base currency
 * is always present at exactly 1, which removes the special case from every
 * lookup and cross-rate below.
 */
final class RateTable
{
    /**
     * @param array<string, int> $rates Quote currency => rate against the base, scaled.
     */
    private function __construct(
        public readonly string $baseCurrency,
        public readonly array $rates,
        public readonly ?DateTimeImmutable $asOfDate,
        public readonly string $provider,
    ) {
    }

    /**
     * @param array<string, int> $rates
     */
    public static function of(
        string $baseCurrency,
        array $rates,
        ?DateTimeImmutable $asOfDate,
        string $provider,
    ): self {
        $base = Currency::normalise($baseCurrency);

        $normalised = [];
        foreach ($rates as $quote => $scaled) {
            $code = Currency::normalise((string) $quote);
            if (!Currency::isValidCode($code) || $scaled <= 0) {
                // A provider occasionally publishes a null or zero for a
                // currency it has no data for that day. Dropping the entry is
                // right; keeping it would mean converting an amount to zero.
                continue;
            }
            $normalised[$code] = $scaled;
        }

        $normalised[$base] = ExchangeRate::SCALE;
        ksort($normalised);

        return new self($base, $normalised, $asOfDate, $provider);
    }

    public function has(string $currency): bool
    {
        return isset($this->rates[Currency::normalise($currency)]);
    }

    /**
     * Re-express the whole table against a different base.
     *
     * rate(new base -> X) = rate(old base -> X) / rate(old base -> new base).
     * Providers whose free tier publishes only one base — Fixer's is EUR — are
     * usable for any base through exactly this step, and it is done once here
     * rather than at each conversion.
     */
    public function rebasedTo(string $newBase): ?self
    {
        $target = Currency::normalise($newBase);
        if ($target === $this->baseCurrency) {
            return $this;
        }

        $divisor = $this->rates[$target] ?? null;
        if ($divisor === null) {
            return null;
        }

        $rebased = [];
        foreach ($this->rates as $quote => $scaled) {
            $rebased[$quote] = Rounding::multiplyDivide($scaled, ExchangeRate::SCALE, $divisor);
        }

        return self::of($target, $rebased, $this->asOfDate, $this->provider);
    }

    /**
     * @return list<ExchangeRate>
     */
    public function toExchangeRates(): array
    {
        $rates = [];
        foreach ($this->rates as $quote => $scaled) {
            $rates[] = ExchangeRate::of($this->baseCurrency, $quote, $scaled, $this->asOfDate, $this->provider);
        }

        return $rates;
    }
}
