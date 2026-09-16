<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One row per domain a logo has been looked for on.
 *
 * This is what makes the fetching bearable. Without it, twenty households each
 * adding the same streaming service would mean twenty outbound requests for
 * the same icon, and a domain that has no icon at all would be asked again on
 * every save for ever.
 *
 * `failed_at` is the half that is easy to leave out and expensive to omit: a
 * negative result is a result, and remembering it for a while is the
 * difference between one failed request per domain per week and one per save.
 *
 * The cached file is the original as fetched. Each subscription still gets its
 * own copy through LogoStorage, so deleting one subscription's logo can never
 * take another's with it — the dedup this table buys is of network requests,
 * not of disk.
 */
final class CreateLogoCacheTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('logo_cache', ['id' => false, 'primary_key' => ['domain']])
            ->addColumn('domain', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('cached_path', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('fetched_at', 'timestamp', ['null' => true])
            ->addColumn('failed_at', 'timestamp', ['null' => true])
            ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0])
            ->create();
    }

    public function down(): void
    {
        $this->table('logo_cache')->drop()->save();
    }
}
