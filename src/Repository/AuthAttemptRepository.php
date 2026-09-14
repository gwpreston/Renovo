<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Database;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The ledger behind login and password-reset throttling.
 *
 * Attempts are recorded against both a hashed account key and the client IP so
 * the two limits can be applied independently: a password-spraying attacker
 * hitting many accounts from one address is throttled by the IP limit, and a
 * distributed attack on one account is throttled by the account limit.
 *
 * The account key is stored as a hash so that the table does not become a list
 * of which email addresses exist on the instance.
 */
final class AuthAttemptRepository extends AbstractRepository
{
    public const KIND_LOGIN = 'login';
    public const KIND_RESET = 'reset';

    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'auth_attempts';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'kind', 'account_key', 'ip_address', 'attempted_at', 'successful'];
    }

    public function record(string $kind, string $accountKey, string $ipAddress, bool $successful): void
    {
        $this->db->insert('auth_attempts', [
            'kind' => $kind,
            'account_key' => $this->hashAccountKey($accountKey),
            'ip_address' => substr($ipAddress, 0, 45),
            'successful' => $successful,
            'attempted_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function countFailuresForAccount(string $kind, string $accountKey, DateTimeImmutable $since): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('kind') . ' = :kind'
            . ' AND ' . $this->quote('account_key') . ' = :account'
            . ' AND ' . $this->quote('successful') . ' = :failed'
            . ' AND ' . $this->quote('attempted_at') . ' >= :since',
            [
                'kind' => $kind,
                'account' => $this->hashAccountKey($accountKey),
                'failed' => false,
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function countFailuresForIp(string $kind, string $ipAddress, DateTimeImmutable $since): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('kind') . ' = :kind'
            . ' AND ' . $this->quote('ip_address') . ' = :ip'
            . ' AND ' . $this->quote('successful') . ' = :failed'
            . ' AND ' . $this->quote('attempted_at') . ' >= :since',
            [
                'kind' => $kind,
                'ip' => substr($ipAddress, 0, 45),
                'failed' => false,
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Clear an account's failures after a successful authentication, so a user
     * who eventually remembers their password is not left locked out.
     */
    public function clearForAccount(string $kind, string $accountKey): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('kind') . ' = :kind'
            . ' AND ' . $this->quote('account_key') . ' = :account',
            ['kind' => $kind, 'account' => $this->hashAccountKey($accountKey)],
        );
    }

    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('attempted_at') . ' < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }

    private function hashAccountKey(string $accountKey): string
    {
        return hash('sha256', mb_strtolower(trim($accountKey)));
    }
}
