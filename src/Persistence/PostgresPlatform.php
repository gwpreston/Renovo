<?php

declare(strict_types=1);

namespace App\Persistence;

final class PostgresPlatform implements Platform
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function dsn(array $config): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=--client_encoding=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            str_replace('-', '', $config['charset']),
        );
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function supportsInsertReturning(): bool
    {
        return true;
    }

    public function insertIdSequence(string $table, string $idColumn): string
    {
        return $table . '_' . $idColumn . '_seq';
    }

    public function booleanParameter(bool $value): mixed
    {
        return $value;
    }

    public function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['t', 'true', '1', 'y', 'yes'], true);
        }

        return (bool) $value;
    }

    public function caseInsensitiveLike(string $quotedColumn, string $placeholder): string
    {
        return sprintf('LOWER(%s) LIKE %s', $quotedColumn, $placeholder);
    }

    public function connectionOptions(): array
    {
        return [];
    }

    public function initialStatements(): array
    {
        // Keep timestamp handling predictable regardless of server locale.
        return ["SET TIME ZONE 'UTC'"];
    }
}
