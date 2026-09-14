<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * The differences between the supported database engines, in one place.
 *
 * Application code never branches on the driver name; it asks the platform.
 * Everything listed here is something PostgreSQL and MySQL/MariaDB genuinely
 * disagree about and that this application actually uses.
 */
interface Platform
{
    public function name(): string;

    /**
     * Build a PDO DSN from connection settings.
     *
     * @param array{host: string, port: int, name: string, charset: string} $config
     */
    public function dsn(array $config): string;

    /**
     * Quote a table or column name.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * True when INSERT ... RETURNING can be used to obtain the new id, which
     * is both cheaper and safer than a separate lastInsertId() round trip.
     */
    public function supportsInsertReturning(): bool;

    /**
     * The sequence name PDO::lastInsertId() needs, or null when the driver
     * does not take one.
     */
    public function insertIdSequence(string $table, string $idColumn): ?string;

    /**
     * Convert a PHP boolean into the value the driver expects to bind.
     */
    public function booleanParameter(bool $value): mixed;

    /**
     * Convert a value read back from the database into a PHP boolean.
     * PostgreSQL yields 't'/'f' or true/false, MySQL yields 1/0.
     */
    public function toBoolean(mixed $value): bool;

    /**
     * A case-insensitive LIKE predicate. ILIKE is PostgreSQL-only, so both
     * engines use LOWER(column) LIKE ? and callers lower-case the pattern.
     */
    public function caseInsensitiveLike(string $quotedColumn, string $placeholder): string;

    /**
     * Driver-specific connection options merged over the shared defaults.
     *
     * @return array<int, mixed>
     */
    public function connectionOptions(): array;

    /**
     * Statements run once immediately after connecting.
     *
     * @return list<string>
     */
    public function initialStatements(): array;
}
