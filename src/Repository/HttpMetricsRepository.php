<?php

declare(strict_types=1);

namespace App\Repository;

use Throwable;

/**
 * The HTTP counters behind `/metrics`.
 *
 * Every method swallows its errors. These are counters: a failed increment
 * costs a number, and a request that 500s because the metrics table is missing
 * costs the user their page.
 */
final class HttpMetricsRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'http_metrics';
    }

    protected function filterableColumns(): array
    {
        return ['bucket'];
    }

    public function record(string $bucket, int $durationMicroseconds): void
    {
        $params = ['bucket' => $bucket, 'duration' => max(0, $durationMicroseconds)];

        try {
            $updated = $this->db->execute(
                'UPDATE ' . $this->quote('http_metrics')
                . ' SET ' . $this->quote('requests') . ' = ' . $this->quote('requests') . ' + 1,'
                . ' ' . $this->quote('duration_us') . ' = ' . $this->quote('duration_us') . ' + :duration'
                . ' WHERE ' . $this->quote('bucket') . ' = :bucket',
                $params,
            );

            if ($updated === 0) {
                $this->db->execute(
                    'INSERT INTO ' . $this->quote('http_metrics') . ' ('
                    . $this->quote('bucket') . ', ' . $this->quote('requests') . ', '
                    . $this->quote('duration_us') . ') VALUES (:bucket, 1, :duration)',
                    $params,
                );
            }
        } catch (Throwable) {
            // A counter is not worth a 500.
        }
    }

    /**
     * @return array<string, array{requests: int, duration_us: int}>
     */
    public function all(): array
    {
        try {
            $rows = $this->db->fetchAll('SELECT * FROM ' . $this->quote('http_metrics'));
        } catch (Throwable) {
            return [];
        }

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['bucket']] = [
                'requests' => (int) ($row['requests'] ?? 0),
                'duration_us' => (int) ($row['duration_us'] ?? 0),
            ];
        }

        return $totals;
    }
}
