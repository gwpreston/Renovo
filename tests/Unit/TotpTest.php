<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\Totp;
use PHPUnit\Framework\TestCase;

/**
 * The TOTP implementation, checked against RFC 6238's own test vectors.
 *
 * Those vectors are the reason this algorithm is written here rather than
 * pulled in: they pin the implementation to the standard every authenticator
 * app implements, which is a stronger guarantee than "the library we chose
 * agrees with itself".
 */
final class TotpTest extends TestCase
{
    /**
     * The RFC's seed: the ASCII string "12345678901234567890".
     */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testBase32RoundTripsTheRfcSeed(): void
    {
        self::assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
        self::assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
    }

    /**
     * RFC 6238 appendix B. The published values are eight digits; a six-digit
     * code is their last six, because the truncation is the same and only the
     * modulus differs.
     *
     * @dataProvider rfcVectors
     */
    public function testMatchesTheRfcTestVectors(int $timestamp, string $expected): void
    {
        self::assertSame($expected, Totp::codeForStep(self::RFC_SECRET, Totp::stepAt($timestamp)));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            '1970-01-01' => [59, '287082'],
            '2005-03-18' => [1111111109, '081804'],
            '2009-02-13' => [1234567890, '005924'],
            '2033-05-18' => [2000000000, '279037'],
        ];
    }

    public function testAcceptsACodeFromTheAdjacentStep(): void
    {
        $now = 1_700_000_000;
        $previous = Totp::codeForStep(self::RFC_SECRET, Totp::stepAt($now) - 1);

        // A phone whose clock is half a minute slow still works.
        self::assertNotNull(Totp::verify(self::RFC_SECRET, $previous, $now));
    }

    public function testRejectsACodeOutsideTheDriftWindow(): void
    {
        $now = 1_700_000_000;
        $stale = Totp::codeForStep(self::RFC_SECRET, Totp::stepAt($now) - 5);

        self::assertNull(Totp::verify(self::RFC_SECRET, $stale, $now));
    }

    /**
     * The replay defence: a code that has been used cannot be used again while
     * it is still arithmetically valid.
     */
    public function testRefusesAStepThatHasAlreadyBeenUsed(): void
    {
        $now = 1_700_000_000;
        $step = Totp::stepAt($now);
        $code = Totp::codeForStep(self::RFC_SECRET, $step);

        self::assertSame($step, Totp::verify(self::RFC_SECRET, $code, $now));
        self::assertNull(Totp::verify(self::RFC_SECRET, $code, $now, $step));
    }

    public function testIgnoresSpacingAndPunctuationInASubmittedCode(): void
    {
        $now = 1_700_000_000;
        $code = Totp::codeForStep(self::RFC_SECRET, Totp::stepAt($now));
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        self::assertNotNull(Totp::verify(self::RFC_SECRET, $spaced, $now));
    }

    public function testRejectsACodeOfTheWrongLength(): void
    {
        self::assertNull(Totp::verify(self::RFC_SECRET, '12345', 1_700_000_000));
        self::assertNull(Totp::verify(self::RFC_SECRET, '', 1_700_000_000));
    }

    public function testGeneratedSecretsAreUsableAndDistinct(): void
    {
        $first = Totp::generateSecret();
        $second = Totp::generateSecret();

        self::assertNotSame($first, $second);
        self::assertSame(32, strlen($first));
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $first);

        $now = 1_700_000_000;
        self::assertNotNull(Totp::verify($first, Totp::codeForStep($first, Totp::stepAt($now)), $now));
    }

    public function testProvisioningUriCarriesTheIssuerTwice(): void
    {
        $uri = Totp::provisioningUri(self::RFC_SECRET, 'user@example.test', 'Renovo Test');

        self::assertStringStartsWith('otpauth://totp/Renovo%20Test:user%40example.test?', $uri);
        self::assertStringContainsString('issuer=Renovo%20Test', $uri);
        self::assertStringContainsString('secret=' . self::RFC_SECRET, $uri);
        self::assertStringContainsString('period=30', $uri);
        self::assertStringContainsString('digits=6', $uri);
    }
}
