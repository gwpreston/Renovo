<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\User;
use DateTimeImmutable;

/**
 * Users are an instance-level concept, not household data, so this repository
 * is unscoped. Anything reachable *through* a user — their subscriptions —
 * goes through a scoped repository instead.
 */
final class UserRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'users';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'email', 'display_name', 'is_instance_admin', 'created_at'];
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('users') . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('users') . ' WHERE ' . $this->quote('email') . ' = :email',
            ['email' => $this->normaliseEmail($email)],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function emailExists(string $email): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote('users') . ' WHERE ' . $this->quote('email') . ' = :email',
            ['email' => $this->normaliseEmail($email)],
        ) !== null;
    }

    public function create(
        string $email,
        string $displayName,
        string $passwordHash,
        bool $isInstanceAdmin = false,
        ?DateTimeImmutable $emailVerifiedAt = null,
    ): int {
        $now = new DateTimeImmutable();

        return $this->db->insert('users', [
            'email' => $this->normaliseEmail($email),
            'display_name' => $displayName,
            'password_hash' => $passwordHash,
            'is_instance_admin' => $isInstanceAdmin,
            'email_verified_at' => $emailVerifiedAt?->format('Y-m-d H:i:s'),
            'theme' => 'system',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function markEmailVerified(int $userId, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('users') . ' SET ' . $this->quote('email_verified_at') . ' = :at, '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            [
                'at' => $at->format('Y-m-d H:i:s'),
                'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $userId,
            ],
        );
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('users') . ' SET ' . $this->quote('password_hash') . ' = :hash, '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            ['hash' => $hash, 'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $userId],
        );
    }

    public function updateTheme(int $userId, string $theme): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('users') . ' SET ' . $this->quote('theme') . ' = :theme, '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            ['theme' => $theme, 'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $userId],
        );
    }

    public function countAll(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . $this->quote('users'));
    }

    /**
     * @return list<User>
     */
    public function findAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('users') . ' ORDER BY ' . $this->quote('display_name') . ' ASC',
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        $verifiedAt = $row['email_verified_at'];

        return new User(
            id: (int) $row['id'],
            email: (string) $row['email'],
            displayName: (string) $row['display_name'],
            passwordHash: (string) $row['password_hash'],
            isInstanceAdmin: $this->db->platform()->toBoolean($row['is_instance_admin']),
            emailVerifiedAt: is_string($verifiedAt) && $verifiedAt !== ''
                ? new DateTimeImmutable($verifiedAt)
                : null,
            theme: (string) ($row['theme'] ?? 'system'),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
