<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Currency;
use App\Domain\Money;
use NumberFormatter;

/**
 * Display formatting for money. Parsing and arithmetic never come near this
 * class — it takes an already-computed integer minor-unit amount and renders
 * it, which is the only point at which a currency value is allowed to become
 * a human-readable string.
 */
final class MoneyFormatter
{
    /** @var array<string, NumberFormatter> */
    private array $formatters = [];

    public function __construct(private readonly string $locale = 'en_GB')
    {
    }

    public function format(Money $money): string
    {
        $formatter = $this->formatter($this->locale);
        $exponent = Currency::exponent($money->currency);

        // intl expects a major-unit value. The division happens here, at the
        // very edge of the system, and its result is never fed back in.
        $major = $exponent === 0
            ? (float) $money->amountMinor
            : $money->amountMinor / (10 ** $exponent);

        $formatted = $formatter->formatCurrency($major, $money->currency);

        return $formatted === false
            ? $money->currency . ' ' . $money->toDecimalString()
            : $formatted;
    }

    public function formatMinor(int $amountMinor, string $currency): string
    {
        return $this->format(Money::of($amountMinor, $currency));
    }

    private function formatter(string $locale): NumberFormatter
    {
        return $this->formatters[$locale] ??= new NumberFormatter($locale, NumberFormatter::CURRENCY);
    }
}
