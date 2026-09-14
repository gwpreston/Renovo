<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Database;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Single-use tokens for email verification and password reset.
 *
 * Only a hash of each token is stored: a leaked database backup must not hand
 * an attacker a working password-reset link. Lookups therefore hash the
 * presented token and compare, which is also constant-time by construction.
 */
final class TokenRepository extends AbstractRepository
{
    public const PURPOSE_VERIFY_EMAIL = 'verify_email';
    public const PURPOSE_RESET_PASSWORD = 'reset_password';

    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'auth_tokens';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'user_id', 'purpose', 'expires_at', 'consumed_at'];
    }

    /**
     * @return string The plain token, to be emailed. It is not recoverable
     *                afterwards.
     */
    public function issue(int $userId, string $purpose, DateTimeImmutable $expiresAt): string
    {
        // Any previous unused token for the same purpose is invalidated, so a
        // user who requests two resets cannot be confused by the older link.
        $this->db->execute(
            'DELETE FROM ' . $this->quote('auth_tokens')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('purpose') . ' = :purpose',
            ['user' => $userId, 'purpose' => $purpose],
        );

        $plain = bin2hex(random_bytes(32));

        $this->db->insert('auth_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $this->hash($plain),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => $this->now(),
        ]);

        return $plain;
    }

    /**
     * Look up a valid, unconsumed token and return the user it belongs to.
     */
    public function findValidUserId(string $plainToken, string $purpose): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->quote('user_id') . ' FROM ' . $this->quote('auth_tokens')
            . ' WHERE ' . $this->quote('token_hash') . ' = :hash'
            . ' AND ' . $this->quote('purpose') . ' = :purpose'
            . ' AND ' . $this->quote('consumed_at') . ' IS NULL'
            . ' AND ' . $this->quote('expires_at') . ' > :now',
            [
                'hash' => $this->hash($plainToken),
                'purpose' => $purpose,
                'now' => $this->now(),
            ],
        );

        return $row === null ? null : (int) $row['user_id'];
    }

    public function consume(string $plainToken, string $purpose): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->quote('auth_tokens') . ' SET ' . $this->quote('consumed_at') . ' = :now'
            . ' WHERE ' . $this->quote('token_hash') . ' = :hash'
            . ' AND ' . $this->quote('purpose') . ' = :purpose'
            . ' AND ' . $this->quote('consumed_at') . ' IS NULL'
            . ' AND ' . $this->quote('expires_at') . ' > :now2',
            [
                'now' => $this->now(),
                'hash' => $this->hash($plainToken),
                'purpose' => $purpose,
                'now2' => $this->now(),
            ],
        ) > 0;
    }

    public function purgeExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('auth_tokens') . ' WHERE ' . $this->quote('expires_at') . ' < :now',
            ['now' => $this->now()],
        );
    }

    /**
     * Every time comparison in this class uses the injected clock, so token
     * expiry is deterministic and can actually be tested.
     */
    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
