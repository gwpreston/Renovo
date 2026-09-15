<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

/**
 * Hashed single-use recovery codes, for whichever second factor an account uses.
 *
 * There is no "find by code" method, and there cannot be: the codes are hashed
 * with the password hasher, so the only way to match one is to verify the
 * candidate against each unused hash. That is what `findUnused()` is for, and
 * why the service caps how many codes exist.
 */
final class RecoveryCodeRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'recovery_codes';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'user_id', 'used_at'];
    }

    /**
     * @param list<string> $hashes
     */
    public function replaceAll(int $userId, array $hashes, DateTimeImmutable $now): void
    {
        $this->db->transactional(function () use ($userId, $hashes, $now): void {
            $this->deleteAll($userId);

            foreach ($hashes as $hash) {
                $this->db->insert('recovery_codes', [
                    'user_id' => $userId,
                    'code_hash' => $hash,
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'used_at' => null,
                ]);
            }
        });
    }

    /**
     * @return list<array{id: int, code_hash: string}>
     */
    public function findUnused(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('id') . ', ' . $this->quote('code_hash')
            . ' FROM ' . $this->quote('recovery_codes')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('used_at') . ' IS NULL'
            . ' ORDER BY ' . $this->quote('id') . ' ASC',
            ['user' => $userId],
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'code_hash' => (string) $row['code_hash']],
            $rows,
        );
    }

    /**
     * Mark a code used, refusing to do it twice.
     *
     * The `used_at IS NULL` condition is the whole point: two requests arriving
     * with the same code at the same moment both verify the hash, and only the
     * one whose UPDATE affects a row is allowed to proceed.
     */
    public function markUsed(int $id, DateTimeImmutable $at): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->quote('recovery_codes') . ' SET ' . $this->quote('used_at') . ' = :at'
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('used_at') . ' IS NULL',
            ['at' => $at->format('Y-m-d H:i:s'), 'id' => $id],
        ) === 1;
    }

    public function countUnused(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('recovery_codes')
            . ' WHERE ' . $this->quote('user_id') . ' = :user AND ' . $this->quote('used_at') . ' IS NULL',
            ['user' => $userId],
        );
    }

    public function deleteAll(int $userId): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->quote('recovery_codes') . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );
    }
}
