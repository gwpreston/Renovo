<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Counters for the HTTP basics on `/metrics`.
 *
 * A table, because a self-hosted instance may be several PHP processes behind
 * a web server and there is nowhere else they share: a counter in memory would
 * report one worker's view of the world and reset whenever that worker was
 * recycled.
 *
 * One row per status class, so the write is a single-row UPDATE and the whole
 * table is five rows for ever. Nothing is written at all unless the operator
 * has turned metrics on — see MetricsMiddleware, which is only added to the
 * stack when a metrics token is configured.
 */
final class CreateHttpMetricsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('http_metrics', ['id' => false, 'primary_key' => ['bucket']])
            ->addColumn('bucket', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('requests', 'biginteger', ['null' => false, 'default' => 0])
            // Microseconds, summed. Divided by the count it gives a mean
            // response time; kept as an integer so it never becomes a float.
            ->addColumn('duration_us', 'biginteger', ['null' => false, 'default' => 0])
            ->create();
    }

    public function down(): void
    {
        $this->table('http_metrics')->drop()->save();
    }
}
