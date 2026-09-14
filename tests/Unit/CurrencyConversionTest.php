<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\ExchangeRate;
use App\Domain\Rounding;
use ArithmeticError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conversion arithmetic.
 *
 * The interesting cases are not the ordinary ones. They are the currencies
 * whose minor-unit exponent is not two, and the amounts large enough that a
 * naive multiply would overflow into a float — because PHP does that silently,
 * and a float amount of money is the one thing this codebase must never
 * produce.
 */
final class CurrencyConversionTest extends TestCase
{
    public function testConvertsBetweenTwoDecimalCurrencies(): void
    {
        // 1 GBP = 1.17 EUR, so £10.00 is €11.70.
        $rate = ExchangeRate::of('GBP', 'EUR', ExchangeRate::scaleFromDecimalString('1.17'));

        self::assertSame(1170, $rate->convertMinor(1000));
    }

    public function testConvertsIntoAZeroDecimalCurrency(): void
    {
        // 1 GBP = 190.5 JPY. £10.00 (1000 minor) is ¥1905, and yen have no
        // minor units at all, so the result is 1905 — not 190500.
        $rate = ExchangeRate::of('GBP', 'JPY', ExchangeRate::scaleFromDecimalString('190.5'));

        self::assertSame(1905, $rate->convertMinor(1000));
    }

    public function testConvertsOutOfAZeroDecimalCurrency(): void
    {
        // 1 JPY = 0.00525 GBP. ¥10000 (10000 minor, no subdivision) is £52.50,
        // which is 5250 minor units of GBP.
        $rate = ExchangeRate::of('JPY', 'GBP', ExchangeRate::scaleFromDecimalString('0.00525'));

        self::assertSame(5250, $rate->convertMinor(10000));
    }

    public function testConvertsIntoAThreeDecimalCurrency(): void
    {
        // 1 GBP = 0.39 KWD. £100.00 is 39.000 KWD, which is 39000 fils.
        $rate = ExchangeRate::of('GBP', 'KWD', ExchangeRate::scaleFromDecimalString('0.39'));

        self::assertSame(39000, $rate->convertMinor(10000));
    }

    public function testRoundsHalfAwayFromZero(): void
    {
        // 1 GBP = 1.005 EUR on £1.00 gives €1.005 — exactly half a cent. Half
        // away from zero rounds it up, rather than to even.
        $rate = ExchangeRate::of('GBP', 'EUR', ExchangeRate::scaleFromDecimalString('1.005'));

        self::assertSame(101, $rate->convertMinor(100));

        // And the same amount one cent lower is not half, so it rounds down.
        $lower = ExchangeRate::of('GBP', 'EUR', ExchangeRate::scaleFromDecimalString('1.004'));
        self::assertSame(100, $lower->convertMinor(100));
    }

    /**
     * The case the overflow guard exists for.
     *
     * A trillion minor units of yen converted into a three-decimal currency
     * multiplies by 10^3 and by a rate scaled by 10^8. Done naively that is
     * roughly 10^23, which a 64-bit integer cannot hold, and PHP would quietly
     * hand back a float. The result must still be an exact integer.
     */
    public function testLargeAmountAcrossDifferingExponentsStaysAnInteger(): void
    {
        $rate = ExchangeRate::of('JPY', 'KWD', ExchangeRate::scaleFromDecimalString('0.002'));

        // assertSame is the assertion that matters: it compares by type as
        // well as value, so an overflow that had silently produced a float
        // would fail here rather than pass on a loose comparison.
        //
        // ¥1,000,000,000,000 × 0.002 = 2,000,000,000 KWD, and KWD has three
        // minor units, so 2,000,000,000,000 fils.
        self::assertSame(2_000_000_000_000, $rate->convertMinor(1_000_000_000_000));
    }

    public function testAnAmountTooLargeToConvertExactlyThrowsRatherThanReturningAFloat(): void
    {
        $this->expectException(ArithmeticError::class);

        Rounding::multiplyDivide(PHP_INT_MAX - 1, 1_000_000, 7);
    }

    public function testInversionRoundTripsWithinRoundingTolerance(): void
    {
        $rate = ExchangeRate::of('GBP', 'EUR', ExchangeRate::scaleFromDecimalString('1.17'));

        $there = $rate->convertMinor(10_000);
        $back = $rate->inverted()->convertMinor($there);

        self::assertEqualsWithDelta(10_000, $back, 1);
    }

    public function testIdentityRateLeavesAnAmountUntouched(): void
    {
        $rate = ExchangeRate::of('GBP', 'GBP', ExchangeRate::SCALE);

        self::assertSame(1234, $rate->convertMinor(1234));
    }

    #[DataProvider('decimalStrings')]
    public function testParsesPublishedRatesWithoutGoingThroughAFloat(string $input, int $expected): void
    {
        self::assertSame($expected, ExchangeRate::scaleFromDecimalString($input));
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    public static function decimalStrings(): array
    {
        return [
            ['1', 100_000_000],
            ['1.17', 117_000_000],
            ['1.172345', 117_234_500],
            ['0.00000001', 1],
            // More precision than we keep: rounded, not truncated.
            ['1.123456785', 112_345_679],
            ['1.123456784', 112_345_678],
            ['190.5', 19_050_000_000],
        ];
    }

    #[DataProvider('malformedRates')]
    public function testRejectsAnythingThatIsNotAPositiveDecimal(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExchangeRate::scaleFromDecimalString($input);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function malformedRates(): array
    {
        return [
            [''],
            ['nonsense'],
            ['-1.5'],
            ['0'],
            ['1.2.3'],
            ['1e5'],
        ];
    }

    public function testRateRendersBackAsADecimalString(): void
    {
        $rate = ExchangeRate::of('GBP', 'EUR', ExchangeRate::scaleFromDecimalString('1.17'));

        self::assertSame('1.17000000', $rate->toDecimalString());
    }
}
