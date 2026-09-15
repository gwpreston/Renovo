<?php

declare(strict_types=1);

namespace App\Persistence;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Database-backed session storage.
 *
 * Sessions are kept in the database rather than on disk so the app container
 * stays stateless: restarting it, or running more than one of it, does not log
 * everybody out. It also means session rows can be inspected and, in a later
 * phase, listed and revoked from the UI.
 */
final class PdoSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private const TABLE = 'sessions';

    private ?string $clientIp = null;

    private ?string $userAgent = null;

    private ?int $userId = null;

    public function __construct(
        private readonly Database $db,
        private readonly int $lifetimeSeconds,
    ) {
    }

    /**
     * Tell the handler who is on the other end, so that a row created during
     * this request can say where it came from.
     *
     * Set once by the session middleware before the session starts. The detail
     * is written when the row is inserted and never on a later write: a session
     * is a device, and rewriting the device on every request would turn the list
     * on the security page into a list of the last page load.
     */
    public function describeClient(?string $ipAddress, ?string $userAgent): void
    {
        $this->clientIp = $ipAddress;
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, 255);
    }

    /**
     * Name the account this session belongs to, so it can be listed and
     * revoked.
     *
     * Set by the session middleware from the session's own contents just before
     * the row is written, rather than by whichever controller happens to sign a
     * user in. A session that PHP writes is a session the column is correct for,
     * including after a logout clears it — there is no sign-in path that can
     * forget to call this.
     */
    public function associateUser(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->column('payload') . ' FROM ' . $this->table()
            . ' WHERE ' . $this->column('id') . ' = :id'
            . ' AND ' . $this->column('expires_at') . ' > :now',
            ['id' => $id, 'now' => $this->now()],
        );

        if ($row === null) {
            return '';
        }

        $payload = $row['payload'];
        if (is_resource($payload)) {
            $contents = stream_get_contents($payload);

            return $contents === false ? '' : $contents;
        }

        return is_string($payload) ? $payload : '';
    }

    public function write(string $id, string $data): bool
    {
        $now = $this->now();
        $expires = date('Y-m-d H:i:s', time() + $this->lifetimeSeconds);

        $updated = $this->db->execute(
            'UPDATE ' . $this->table() . ' SET ' . $this->column('payload') . ' = :payload, '
            . $this->column('last_activity') . ' = :now, ' . $this->column('expires_at') . ' = :expires, '
            . $this->column('user_id') . ' = :user'
            . ' WHERE ' . $this->column('id') . ' = :id',
            ['payload' => $data, 'now' => $now, 'expires' => $expires, 'user' => $this->userId, 'id' => $id],
        );

        if ($updated > 0) {
            return true;
        }

        // Zero affected rows does not mean the row is missing on MySQL: it
        // reports rows *changed*, so re-writing a session with identical
        // contents inside the same second looks exactly like a session that
        // does not exist yet. Inserting on that basis would hit the primary
        // key. PostgreSQL reports matched rows and needs no second query. The
        // same distinction is handled the same way in InstanceSettingsRepository.
        if (!$this->db->platform()->reportsMatchedRowsOnUpdate() && $this->exists($id)) {
            return true;
        }

        $this->db->execute(
            'INSERT INTO ' . $this->table() . ' (' . $this->column('id') . ', ' . $this->column('payload')
            . ', ' . $this->column('last_activity') . ', ' . $this->column('expires_at')
            . ', ' . $this->column('user_id') . ', ' . $this->column('ip_address')
            . ', ' . $this->column('user_agent') . ', ' . $this->column('created_at') . ')'
            . ' VALUES (:id, :payload, :now, :expires, :user, :ip, :agent, :created)',
            [
                'id' => $id,
                'payload' => $data,
                'now' => $now,
                'expires' => $expires,
                'user' => $this->userId,
                'ip' => $this->clientIp,
                'agent' => $this->userAgent,
                'created' => $now,
            ],
        );

        return true;
    }

    private function exists(string $id): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->table() . ' WHERE ' . $this->column('id') . ' = :id',
            ['id' => $id],
        ) !== null;
    }

    public function destroy(string $id): bool
    {
        $this->db->execute(
            'DELETE FROM ' . $this->table() . ' WHERE ' . $this->column('id') . ' = :id',
            ['id' => $id],
        );

        return true;
    }

    /**
     * @return int Number of deleted sessions.
     */
    public function gc(int $max_lifetime): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->table() . ' WHERE ' . $this->column('expires_at') . ' < :now',
            ['now' => $this->now()],
        );
    }

    public function validateId(string $id): bool
    {
        return $this->read($id) !== '';
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    private function table(): string
    {
        return $this->db->platform()->quoteIdentifier(self::TABLE);
    }

    private function column(string $name): string
    {
        return $this->db->platform()->quoteIdentifier($name);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
