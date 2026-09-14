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

    public function __construct(
        private readonly Database $db,
        private readonly int $lifetimeSeconds,
    ) {
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
            . $this->column('last_activity') . ' = :now, ' . $this->column('expires_at') . ' = :expires'
            . ' WHERE ' . $this->column('id') . ' = :id',
            ['payload' => $data, 'now' => $now, 'expires' => $expires, 'id' => $id],
        );

        if ($updated > 0) {
            return true;
        }

        $this->db->execute(
            'INSERT INTO ' . $this->table() . ' (' . $this->column('id') . ', ' . $this->column('payload')
            . ', ' . $this->column('last_activity') . ', ' . $this->column('expires_at') . ')'
            . ' VALUES (:id, :payload, :now, :expires)',
            ['id' => $id, 'payload' => $data, 'now' => $now, 'expires' => $expires],
        );

        return true;
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

    /**
     * Associate a session row with a user, so sessions can be revoked when a
     * password changes.
     */
    public function attachUser(string $sessionId, ?int $userId): void
    {
        $this->db->execute(
            'UPDATE ' . $this->table() . ' SET ' . $this->column('user_id') . ' = :user'
            . ' WHERE ' . $this->column('id') . ' = :id',
            ['user' => $userId, 'id' => $sessionId],
        );
    }

    public function deleteForUser(int $userId, ?string $exceptSessionId = null): void
    {
        $sql = 'DELETE FROM ' . $this->table() . ' WHERE ' . $this->column('user_id') . ' = :user';
        $params = ['user' => $userId];

        if ($exceptSessionId !== null) {
            $sql .= ' AND ' . $this->column('id') . ' <> :except';
            $params['except'] = $exceptSessionId;
        }

        $this->db->execute($sql, $params);
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
