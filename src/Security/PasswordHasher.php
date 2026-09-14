<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Password hashing with argon2id, falling back to bcrypt when the build of
 * PHP lacks libsodium/argon2 support.
 *
 * `needsRehash()` lets a login transparently upgrade an old bcrypt hash the
 * next time the user proves the password, so a fallback is never permanent.
 */
final class PasswordHasher
{
    /** @var array<string, int> */
    private array $options;

    private string $algorithm;

    public function __construct()
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            $this->algorithm = PASSWORD_ARGON2ID;
            $this->options = [
                'memory_cost' => 64 * 1024,
                'time_cost' => 4,
                'threads' => 2,
            ];

            return;
        }

        $this->algorithm = PASSWORD_BCRYPT;
        $this->options = ['cost' => 12];
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, $this->algorithm, $this->options);
    }

    public function verify(string $plain, string $hash): bool
    {
        if ($hash === '') {
            // Still spend the time, so a missing hash is not distinguishable
            // from a wrong password by how long the response takes.
            password_verify($plain, '$2y$12$usesomesillystringforsalt0000000000000000000000000000000');

            return false;
        }

        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    public function algorithmName(): string
    {
        return $this->algorithm === PASSWORD_BCRYPT ? 'bcrypt' : 'argon2id';
    }
}
