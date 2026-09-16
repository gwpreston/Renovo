<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

/**
 * What has already been looked for, and where it was found.
 *
 * Keyed by domain and shared by every household on the instance. That is
 * deliberate and it discloses nothing: a row says only that some account here
 * has a subscription whose website is on that domain, which is exactly what the
 * outbound request said at the time anyway.
 */
final class LogoCacheRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'logo_cache';
    }

    protected function filterableColumns(): array
    {
        return ['domain'];
    }

    /**
     * @return array{domain: string, cached_path: string|null, fetched_at: DateTimeImmutable|null,
     *     failed_at: DateTimeImmutable|null, attempts: int}|null
     */
    public function find(string $domain): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('logo_cache') . ' WHERE ' . $this->quote('domain') . ' = :domain',
            ['domain' => $domain],
        );

        if ($row === null) {
            return null;
        }

        $fetchedAt = $row['fetched_at'] ?? null;
        $failedAt = $row['failed_at'] ?? null;

        return [
            'domain' => (string) $row['domain'],
            'cached_path' => isset($row['cached_path']) && $row['cached_path'] !== ''
                ? (string) $row['cached_path']
                : null,
            'fetched_at' => is_string($fetchedAt) && $fetchedAt !== '' ? new DateTimeImmutable($fetchedAt) : null,
            'failed_at' => is_string($failedAt) && $failedAt !== '' ? new DateTimeImmutable($failedAt) : null,
            'attempts' => (int) ($row['attempts'] ?? 0),
        ];
    }

    public function recordSuccess(string $domain, string $cachedPath, DateTimeImmutable $at): void
    {
        $this->upsert($domain, [
            'cached_path' => $cachedPath,
            'fetched_at' => $at->format('Y-m-d H:i:s'),
            'failed_at' => null,
        ]);
    }

    public function recordFailure(string $domain, DateTimeImmutable $at): void
    {
        $this->upsert($domain, [
            'cached_path' => null,
            'fetched_at' => null,
            'failed_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Written as an update-then-insert rather than as one of the engines'
     * upsert dialects: `ON CONFLICT` and `ON DUPLICATE KEY` are the two
     * spellings this application would otherwise have to carry both of, and
     * the race — two saves for the same domain at the same instant — loses at
     * worst one cache write.
     *
     * @param array<string, string|null> $values
     */
    private function upsert(string $domain, array $values): void
    {
        $assignments = [];
        foreach (array_keys($values) as $column) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
        }

        $updated = $this->db->execute(
            'UPDATE ' . $this->quote('logo_cache') . ' SET ' . implode(', ', $assignments)
            . ', ' . $this->quote('attempts') . ' = ' . $this->quote('attempts') . ' + 1'
            . ' WHERE ' . $this->quote('domain') . ' = :domain',
            $values + ['domain' => $domain],
        );

        if ($updated > 0) {
            return;
        }

        $this->db->execute(
            'INSERT INTO ' . $this->quote('logo_cache') . ' ('
            . $this->quote('domain') . ', ' . $this->quote('cached_path') . ', '
            . $this->quote('fetched_at') . ', ' . $this->quote('failed_at') . ', '
            . $this->quote('attempts')
            . ') VALUES (:domain, :cached_path, :fetched_at, :failed_at, 1)',
            $values + ['domain' => $domain],
        );
    }
}
