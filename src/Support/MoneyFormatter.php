<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Currency;
use App\Domain\Money;
use App\I18n\LocaleContext;
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

    /** @var array<string, NumberFormatter> */
    private array $symbols = [];

    /**
     * The locale is read per call rather than fixed at construction: a user
     * who reads the application in German sees German thousands separators,
     * and the scheduler formats each recipient's figures in their own locale
     * while building their digest.
     */
    public function __construct(private readonly LocaleContext $locale)
    {
    }

    /**
     * `signed` prefixes a positive amount with the locale's own plus sign,
     * which is what a change between two figures needs: a price that went up
     * reads as "+£2.00" rather than as an amount that happens to be printed in
     * red. The sign comes from ICU rather than from a `+` in a template, for
     * the same reason the grouping separator does.
     */
    public function format(Money $money, bool $signed = false): string
    {
        $formatter = $this->formatter($this->locale->get());
        $exponent = Currency::exponent($money->currency);

        // intl expects a major-unit value. The division happens here, at the
        // very edge of the system, and its result is never fed back in.
        $major = $exponent === 0
            ? (float) $money->amountMinor
            : $money->amountMinor / (10 ** $exponent);

        $formatted = $formatter->formatCurrency($major, $money->currency);

        if ($formatted === false) {
            $formatted = $money->currency . ' ' . $money->toDecimalString();
        }

        if ($signed && $money->amountMinor > 0) {
            $plus = $formatter->getSymbol(NumberFormatter::PLUS_SIGN_SYMBOL);

            return ($plus === false ? '+' : $plus) . $formatted;
        }

        return $formatted;
    }

    public function formatMinor(int $amountMinor, string $currency, bool $signed = false): string
    {
        return $this->format(Money::of($amountMinor, $currency), $signed);
    }

    /**
     * The currency's sign as the reader's locale writes it — "£", "€", or
     * "US$" in a locale where a bare "$" would be ambiguous — for a label
     * beside the code. The code itself when ICU has no sign for it.
     */
    public function symbol(string $currency): string
    {
        $locale = $this->locale->get();

        // A formatter of its own: setting the currency on the shared one
        // would change what every later format() call printed.
        $formatter = $this->symbols[$locale . '|' . $currency] ??= new NumberFormatter(
            $locale . '@currency=' . $currency,
            NumberFormatter::CURRENCY,
        );
        $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        // "¤" is ICU's placeholder for a currency it has no sign for, which
        // tells a reader less than the code beside it already does.
        return $symbol === false || $symbol === '' || $symbol === '¤' ? $currency : $symbol;
    }

    private function formatter(string $locale): NumberFormatter
    {
        return $this->formatters[$locale] ??= new NumberFormatter($locale, NumberFormatter::CURRENCY);
    }
}
