<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated encryption for the few secrets that have to be stored
 * recoverably.
 *
 * A TOTP secret cannot be hashed — the server has to reproduce codes from it —
 * so it is the one credential in the application that sits in the database in
 * usable form. Encrypting it does not defend against an attacker who owns the
 * running application (they have the key), and it is not pretended that it
 * does. What it defends against is the realistic case: a database dump, a
 * replica, a backup tarball or a stray `pg_dump` in someone's home directory,
 * none of which carry the environment the key lives in.
 *
 * XChaCha20-Poly1305 via libsodium, key derived from the instance secret with
 * HKDF and a purpose string, so the same environment value cannot produce the
 * same key for two different uses.
 */
final class SecretCipher
{
    private const PREFIX = 'v1:';

    private string $key;

    public function __construct(#[SensitiveParameter] string $instanceSecret, string $purpose = 'totp-secret')
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException(
                'The sodium extension is required to store two-factor secrets. '
                . 'Install ext-sodium (it is bundled with PHP 7.2+ on most builds).',
            );
        }

        if ($instanceSecret === '') {
            throw new RuntimeException('No encryption key is configured. Set SESSION_KEY (see .env.example).');
        }

        $this->key = hash_hkdf('sha256', $instanceSecret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $purpose);
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * @throws RuntimeException when the value was not produced by this key.
     *         A secret that will not decrypt is a configuration error — usually
     *         a rotated key — and silently treating it as "no second factor"
     *         would turn that error into an account with its 2FA quietly off.
     */
    public function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, self::PREFIX)) {
            throw new RuntimeException('Stored secret is not in a recognised format.');
        }

        $raw = base64_decode(substr($ciphertext, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored secret is truncated or corrupt.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            $this->key,
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'A stored two-factor secret could not be decrypted. '
                . 'This usually means TOTP_ENCRYPTION_KEY or SESSION_KEY has changed since it was saved.',
            );
        }

        return $plaintext;
    }
}
