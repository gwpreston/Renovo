<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One currency pair's conversion factor, held as an integer.
 *
 * The rate is stored multiplied by 10^SCALE_EXPONENT so that no part of the
 * money path ever handles a float. Providers publish rates as decimal strings;
 * `fromDecimalString()` is the single point at which such a string becomes a
 * number, and it does so by string manipulation rather than by casting, so a
 * value like "1.005" cannot pick up a representation error on the way in.
 */
final class ExchangeRate
{
    /**
     * Eight decimal places. Comfortably finer than any daily published rate,
     * and small enough that a rate times a realistic amount stays inside a
     * 64-bit integer once Rounding has cancelled the common factors.
     */
    public const SCALE_EXPONENT = 8;

    public const SCALE = 10 ** self::SCALE_EXPONENT;

    private function __construct(
        public readonly string $baseCurrency,
        public readonly string $quoteCurrency,
        public readonly int $rateScaled,
        public readonly ?DateTimeImmutable $asOfDate,
        public readonly string $provider,
    ) {
    }

    public static function of(
        string $baseCurrency,
        string $quoteCurrency,
        int $rateScaled,
        ?DateTimeImmutable $asOfDate = null,
        string $provider = '',
    ): self {
        if ($rateScaled <= 0) {
            throw new InvalidArgumentException('An exchange rate must be positive.');
        }

        return new self(
            Currency::normalise($baseCurrency),
            Currency::normalise($quoteCurrency),
            $rateScaled,
            $asOfDate,
            $provider,
        );
    }

    /**
     * Parse a provider's decimal rate ("1.172345", "0.0084", "137") without
     * going through a float.
     *
     * @throws InvalidArgumentException when the value is not a positive decimal.
     */
    public static function scaleFromDecimalString(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal rate.', $value));
        }

        $whole = $matches[1];
        $fraction = $matches[2] ?? '';

        $roundUp = false;
        if (strlen($fraction) > self::SCALE_EXPONENT) {
            $roundUp = (int) $fraction[self::SCALE_EXPONENT] >= 5;
            $fraction = substr($fraction, 0, self::SCALE_EXPONENT);
        }

        $digits = $whole . str_pad($fraction, self::SCALE_EXPONENT, '0');

        // A rate wide enough to overflow is not a rate; it is a malformed feed.
        if (strlen(ltrim($digits, '0')) > 18) {
            throw new InvalidArgumentException(sprintf('Rate "%s" is out of range.', $value));
        }

        $scaled = (int) $digits + ($roundUp ? 1 : 0);

        if ($scaled <= 0) {
            throw new InvalidArgumentException(sprintf('Rate "%s" is not positive.', $value));
        }

        return $scaled;
    }

    /**
     * Convert an amount of minor units from the base currency to the quote
     * currency.
     *
     * Both the rate's scaling and the two currencies' differing minor-unit
     * exponents are folded into a single integer multiply-divide, so there is
     * one rounding step rather than three. Converting ¥1000 (no minor units)
     * into KWD (three) multiplies by 10^3 and divides by 10^0; the reverse
     * divides. Rounding cancels the common factors before multiplying.
     */
    public function convertMinor(int $amountMinor): int
    {
        $fromExponent = Currency::exponent($this->baseCurrency);
        $toExponent = Currency::exponent($this->quoteCurrency);

        return Rounding::multiplyDivide(
            $amountMinor,
            $this->rateScaled * (10 ** $toExponent),
            self::SCALE * (10 ** $fromExponent),
        );
    }

    /**
     * The same pair the other way round.
     *
     * Inverting loses a little precision, which is why it is only used when a
     * provider publishes one direction and the application needs the other.
     */
    public function inverted(): self
    {
        return new self(
            $this->quoteCurrency,
            $this->baseCurrency,
            Rounding::multiplyDivide(self::SCALE, self::SCALE, $this->rateScaled),
            $this->asOfDate,
            $this->provider,
        );
    }

    public function toDecimalString(): string
    {
        $digits = str_pad((string) $this->rateScaled, self::SCALE_EXPONENT + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -self::SCALE_EXPONENT) . '.' . substr($digits, -self::SCALE_EXPONENT);
    }
}
