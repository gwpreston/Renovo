<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Throwable;

/**
 * What Phinx has recorded about this database's schema.
 *
 * Read through a repository rather than by a service reaching for the
 * `phinxlog` table directly — the same rule everything else in this
 * application follows, and it is the rule that makes "where does this SQL
 * live?" answerable.
 *
 * Every method tolerates the table not existing. On a database that has never
 * been migrated the readiness endpoint should say exactly that, not fail with
 * a driver error.
 */
final class MigrationRepository extends AbstractRepository
{
    private const TABLE = 'phinxlog';

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function filterableColumns(): array
    {
        return ['version'];
    }

    public function hasBeenMigrated(): bool
    {
        return $this->latestVersion() !== null;
    }

    /**
     * The highest applied version, as Phinx writes it (a YmdHis stamp).
     */
    public function latestVersion(): ?string
    {
        try {
            $value = $this->db->fetchValue(
                'SELECT MAX(' . $this->quote('version') . ') FROM ' . $this->quote(self::TABLE),
            );
        } catch (Throwable) {
            return null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function appliedCount(): int
    {
        try {
            return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . $this->quote(self::TABLE));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * A migration Phinx started and did not finish. Phinx writes the row when
     * it begins and stamps `end_time` when it succeeds, so a row with a start
     * and no end is a schema that is half-changed — which is the state an
     * operator most needs to be told about and the one an ordinary page load
     * hides best.
     */
    public function hasIncompleteMigration(): bool
    {
        try {
            $count = $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->quote(self::TABLE)
                . ' WHERE ' . $this->quote('end_time') . ' IS NULL',
            );
        } catch (Throwable) {
            return false;
        }

        return (int) $count > 0;
    }

    public function lastAppliedAt(): ?DateTimeImmutable
    {
        try {
            $value = $this->db->fetchValue(
                'SELECT MAX(' . $this->quote('end_time') . ') FROM ' . $this->quote(self::TABLE),
            );
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
