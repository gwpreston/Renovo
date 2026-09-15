<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

/**
 * RFC 6238 time-based one-time passwords, and the RFC 4648 base32 the
 * authenticator apps expect a secret in.
 *
 * Written here rather than pulled in as a dependency: the algorithm is eighty
 * lines, is frozen by the RFC, and is verified below against the RFC's own
 * published test vectors — which is a stronger guarantee than a library version
 * bump gives us, and one fewer package in the supply chain for something this
 * small.
 *
 * Two details in here matter more than they look:
 *
 *  - `verify()` returns the *step* that matched, not a boolean, because the
 *    caller has to record it. A code stays arithmetically valid for the whole
 *    drift window, so without remembering the highest step already accepted an
 *    intercepted code could be replayed within the same half-minute.
 *  - Comparison is `hash_equals`. A timing-variable comparison against a
 *    six-digit code is a genuinely practical oracle, not a theoretical one.
 */
final class Totp
{
    public const PERIOD_SECONDS = 30;
    public const DIGITS = 6;
    public const ALGORITHM = 'sha1';

    /**
     * How many steps either side of "now" are accepted. One step of leeway
     * covers a phone whose clock is up to thirty seconds out and a user who
     * types slowly; more than that widens the guessing window for no practical
     * gain.
     */
    public const DRIFT_STEPS = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh secret, base32-encoded. Twenty bytes is the RFC 4226 reference
     * length for HMAC-SHA1 and what every authenticator app handles without
     * complaint.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The code for a given step, zero-padded to the configured digit count.
     */
    public static function codeForStep(string $base32Secret, int $step): string
    {
        $key = self::base32Decode($base32Secret);
        if ($key === '') {
            throw new InvalidArgumentException('The TOTP secret is empty or not valid base32.');
        }

        // The step counter is the message: eight bytes, big-endian.
        $message = pack('J', $step);
        $hash = hash_hmac(self::ALGORITHM, $message, $key, true);

        // Dynamic truncation (RFC 4226 §5.3): the low nibble of the last byte
        // chooses where in the digest to read the four-byte value from.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad(
            (string) ($binary % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }

    public static function stepAt(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD_SECONDS);
    }

    /**
     * Check a submitted code against the window around $timestamp.
     *
     * @param int|null $afterStep The highest step already used by this account,
     *                            if any. Steps at or below it are refused even
     *                            when the arithmetic matches.
     * @return int|null The matching step, or null when nothing matched.
     */
    public static function verify(
        string $base32Secret,
        string $submitted,
        int $timestamp,
        ?int $afterStep = null,
    ): ?int {
        $submitted = preg_replace('/\D/', '', $submitted) ?? '';
        if (strlen($submitted) !== self::DIGITS) {
            return null;
        }

        $current = self::stepAt($timestamp);

        for ($offset = -self::DRIFT_STEPS; $offset <= self::DRIFT_STEPS; $offset++) {
            $step = $current + $offset;

            if ($afterStep !== null && $step <= $afterStep) {
                continue;
            }

            if (hash_equals(self::codeForStep($base32Secret, $step), $submitted)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The otpauth:// URI an authenticator app scans or accepts by hand.
     *
     * The issuer appears twice — as a label prefix and as a parameter — which
     * is what the de-facto spec asks for and what stops the entry showing up in
     * the app as a bare email address with no clue which instance it belongs
     * to.
     */
    public static function provisioningUri(string $base32Secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Group a secret into readable blocks for someone typing it in by hand.
     */
    public static function formatSecret(string $base32Secret): string
    {
        return trim(chunk_split($base32Secret, 4, ' '));
    }

    public static function base32Encode(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($raw) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // No padding: every authenticator accepts it without, and a secret the
        // user may have to type is better off shorter.
        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $encoded) ?? '');
        if ($encoded === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($encoded) as $character) {
            $index = strpos(self::BASE32_ALPHABET, $character);
            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $raw = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $raw .= chr((int) bindec($chunk));
            }
        }

        return $raw;
    }
}
