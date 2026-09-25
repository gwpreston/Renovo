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
    /**
     * Every ISO 4217 currency in circulation, for the form's currency select.
     *
     * A list rather than a lookup, because ICU's own currency table also
     * carries every currency that has ever existed (Deutsche Marks, French
     * francs) and PHP exposes no way to ask it which are still tendered.
     * `isValidCode()` stays deliberately looser than this: a stored row in a
     * code that has since been withdrawn is still a valid row.
     */
    private const ALL = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
        'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY',
        'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP',
        'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD',
        'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR',
        'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF',
        'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL',
        'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR',
        'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR',
        'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR',
        'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD',
        'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB',
        'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX',
        'USD', 'UYU', 'UZS', 'VED', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD',
        'XCG', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWG',
    ];

    private const COMMON = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD',
        'HUF', 'ILS', 'INR', 'ISK', 'JPY', 'KRW', 'MXN', 'NOK', 'NZD', 'PLN',
        'RON', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR',
    ];

    /**
     * The currencies a base-currency select leads with, in this order, ahead
     * of the alphabetical rest.
     */
    private const PREFERRED = ['GBP', 'EUR', 'USD'];

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

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }

    /**
     * Every currency, the preferred ones first and the rest alphabetical.
     *
     * @return list<string>
     */
    public static function preferredFirst(): array
    {
        return [...self::PREFERRED, ...array_values(array_diff(self::ALL, self::PREFERRED))];
    }
}
