<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * ISO-4217 currency metadata.
 *
 * Only the minor-unit exponent is needed in this phase: money is always stored
 * and computed as an integer number of minor units, so parsing and formatting
 * must know how many decimal places a currency actually has. Assuming two
 * everywhere silently corrupts JPY (zero) and KWD/BHD/JOD (three).
 *
 * No exchange-rate data lives here — conversion arrives in a later phase.
 */
final class Currency
{
    /**
     * Currencies whose minor-unit exponent is not the default of 2.
     *
     * @var array<string, int>
     */
    private const EXPONENT_OVERRIDES = [
        'BIF' => 0,
        'CLP' => 0,
        'DJF' => 0,
        'GNF' => 0,
        'ISK' => 0,
        'JPY' => 0,
        'KMF' => 0,
        'KRW' => 0,
        'PYG' => 0,
        'RWF' => 0,
        'UGX' => 0,
        'UYI' => 0,
        'VND' => 0,
        'VUV' => 0,
        'XAF' => 0,
        'XOF' => 0,
        'XPF' => 0,
        'BHD' => 3,
        'IQD' => 3,
        'JOD' => 3,
        'KWD' => 3,
        'LYD' => 3,
        'OMR' => 3,
        'TND' => 3,
    ];

    private const DEFAULT_EXPONENT = 2;

    /**
     * A short, deliberately conservative list offered in the UI. Any valid
     * three-letter code is accepted on input; this is only the pick-list.
     *
     * @var list<string>
     */
    private const COMMON = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD',
        'HUF', 'ILS', 'INR', 'ISK', 'JPY', 'KRW', 'MXN', 'NOK', 'NZD', 'PLN',
        'RON', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR',
    ];

    private function __construct()
    {
    }

    /**
     * Number of decimal places the currency subdivides into.
     */
    public static function exponent(string $code): int
    {
        return self::EXPONENT_OVERRIDES[self::normalise($code)] ?? self::DEFAULT_EXPONENT;
    }

    /**
     * The number of minor units in one major unit (100 for USD, 1 for JPY).
     */
    public static function subunits(string $code): int
    {
        return 10 ** self::exponent($code);
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Za-z]{3}$/', trim($code)) === 1;
    }

    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * @return list<string>
     */
    public static function common(): array
    {
        return self::COMMON;
    }
}
