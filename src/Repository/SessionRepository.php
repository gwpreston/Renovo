<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\ActiveSession;
use DateTimeImmutable;

/**
 * Reading and revoking session rows.
 *
 * Separate from PdoSessionHandler on purpose: that class implements PHP's
 * session storage contract and is called by the runtime, while this one answers
 * application questions ("which sessions does this user have?"). Folding the
 * list-and-revoke queries into the handler would mean the security page and the
 * session runtime shared a class whose methods are called from two completely
 * different places for two completely different reasons.
 *
 * Every method is keyed by user id, and the id comes from the session, so one
 * account cannot enumerate or revoke another's.
 */
final class SessionRepository extends AbstractRepository
{
    /**
     * Enough of the session id to identify a row to revoke without printing the
     * whole thing into a page. Sixteen characters of a 48-character identifier
     * is far more than enough to be unique and far too little to be used as one.
     */
    private const HANDLE_LENGTH = 16;

    protected function table(): string
    {
        return 'sessions';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'user_id', 'last_activity', 'expires_at'];
    }

    /**
     * @return list<ActiveSession>
     */
    public function findActiveForUser(int $userId, string $currentSessionId, DateTimeImmutable $now): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('id') . ', ' . $this->quote('ip_address') . ', '
            . $this->quote('user_agent') . ', ' . $this->quote('created_at') . ', '
            . $this->quote('last_activity') . ', ' . $this->quote('expires_at')
            . ' FROM ' . $this->quote('sessions')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('expires_at') . ' > :now'
            . ' ORDER BY ' . $this->quote('last_activity') . ' DESC',
            ['user' => $userId, 'now' => $now->format('Y-m-d H:i:s')],
        );

        return array_map(
            function (array $row) use ($currentSessionId): ActiveSession {
                $id = (string) $row['id'];
                $created = $row['created_at'] ?? null;

                return new ActiveSession(
                    handle: substr($id, 0, self::HANDLE_LENGTH),
                    ipAddress: isset($row['ip_address']) ? (string) $row['ip_address'] : null,
                    userAgent: isset($row['user_agent']) ? (string) $row['user_agent'] : null,
                    createdAt: is_string($created) && $created !== '' ? new DateTimeImmutable($created) : null,
                    lastActivity: new DateTimeImmutable((string) $row['last_activity']),
                    expiresAt: new DateTimeImmutable((string) $row['expires_at']),
                    isCurrent: hash_equals($id, $currentSessionId),
                );
            },
            $rows,
        );
    }

    /**
     * Delete one of this user's sessions, named by its handle.
     *
     * The handle is matched with a prefix comparison against this user's rows
     * only, so a handle from somebody else's session matches nothing. It is
     * bound as a parameter and the `%` is appended here, never taken from input.
     */
    public function deleteByHandle(int $userId, string $handle, string $exceptSessionId): int
    {
        if (!preg_match('/^[A-Za-z0-9,-]{4,64}$/', $handle)) {
            return 0;
        }

        return $this->db->execute(
            'DELETE FROM ' . $this->quote('sessions')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('id') . ' LIKE :handle'
            . ' AND ' . $this->quote('id') . ' <> :current',
            ['user' => $userId, 'handle' => $this->escapeLike($handle) . '%', 'current' => $exceptSessionId],
        );
    }

    /**
     * Every session but the one making the request.
     */
    public function deleteAllExcept(int $userId, string $exceptSessionId): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('sessions')
            . ' WHERE ' . $this->quote('user_id') . ' = :user AND ' . $this->quote('id') . ' <> :current',
            ['user' => $userId, 'current' => $exceptSessionId],
        );
    }

    /**
     * Every session this account has, including the one making the request.
     *
     * The one place that spares nothing, because the account doing the signing
     * out is not the account being signed out. An administrator revoking a
     * member's login is not in any of these rows, and a member who keeps one
     * session after their login is revoked has not had it revoked.
     */
    public function deleteAllForUser(int $userId): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('sessions') . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );
    }

    public function countActiveForUser(int $userId, DateTimeImmutable $now): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('sessions')
            . ' WHERE ' . $this->quote('user_id') . ' = :user AND ' . $this->quote('expires_at') . ' > :now',
            ['user' => $userId, 'now' => $now->format('Y-m-d H:i:s')],
        );
    }
}
