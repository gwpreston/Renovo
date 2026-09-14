<?php

declare(strict_types=1);

namespace App\Persistence;

final class MySqlPlatform implements Platform
{
    public function name(): string
    {
        return 'mysql';
    }

    public function dsn(array $config): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset'] === 'utf8' ? 'utf8mb4' : $config['charset'],
        );
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function supportsInsertReturning(): bool
    {
        return false;
    }

    public function insertIdSequence(string $table, string $idColumn): ?string
    {
        return null;
    }

    public function booleanParameter(bool $value): mixed
    {
        return $value ? 1 : 0;
    }

    public function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        return (bool) $value;
    }

    public function caseInsensitiveLike(string $quotedColumn, string $placeholder): string
    {
        // MySQL's default collation is already case-insensitive, but being
        // explicit keeps behaviour identical across engines and collations.
        return sprintf('LOWER(%s) LIKE %s', $quotedColumn, $placeholder);
    }

    public function connectionOptions(): array
    {
        // Deliberately empty. The obvious candidate, MYSQL_ATTR_INIT_COMMAND,
        // is deprecated in PHP 8.5 in favour of a constant that does not exist
        // before 8.4, so the same session setup is done in initialStatements()
        // instead — which works identically on every supported version.
        return [];
    }

    public function initialStatements(): array
    {
        return [
            // The DSN already requests utf8mb4; this makes the session
            // explicit regardless of server defaults.
            'SET NAMES utf8mb4',
            // Offsets rather than named zones, so no timezone tables needed.
            "SET time_zone = '+00:00'",
            // ONLY_FULL_GROUP_BY and strict mode make MySQL reject the same
            // sloppy queries PostgreSQL already rejects, so bugs surface on
            // both engines rather than only in production.
            "SET SESSION sql_mode = 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'",
        ];
    }
}
