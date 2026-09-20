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

    /**
     * An invitation from a household Owner.
     *
     * A purpose of its own rather than a reused `reset_password`, because the
     * two links do different things to the account at the other end. A reset
     * sets a password on an address that has already been proved; an invite
     * has to prove the address *and* set the first password *and* mark the
     * membership as taken up. Reusing the reset purpose would either leave
     * invited members permanently unverified or teach the reset flow to verify
     * addresses, which is how a password reset quietly becomes a way to
     * confirm one.
     */
    public const PURPOSE_INVITE = 'invite';

    /**
     * A change of address, confirmed at the address being moved to.
     *
     * The token is the only thing that proves the member can read mail at the
     * new address, which is why the live `email` does not move until one comes
     * back. A string constant and not a schema change: `auth_tokens.purpose`
     * has always been free text.
     */
    public const PURPOSE_CONFIRM_EMAIL_CHANGE = 'confirm_email_change';

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
