<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The per-account display preferences, alongside the theme column that has
 * been here since Phase 1.
 *
 * Columns rather than a key-value table or a JSON blob: each is a scalar with
 * a fixed set of values, every one of them is read on every request that
 * renders a page, and JSON operators are one of the few places Postgres and
 * MySQL genuinely diverge. The one preference that is *not* a scalar — which
 * dashboard cards are shown and in what order — gets a table of its own.
 *
 * An empty locale means "whatever the instance is configured for", which is
 * what every existing account gets: an operator who has set APP_LOCALE should
 * not find their users pinned to the value it happened to have on the day
 * this ran.
 */
final class AddDisplayPreferencesToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('locale', 'string', ['limit' => 12, 'null' => false, 'default' => ''])
            // 0 = Sunday, 1 = Monday, matching ISO-8601's numbering for the
            // days themselves. Stored as an integer so the calendar's grid
            // arithmetic needs no lookup.
            ->addColumn('week_start', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('density', 'string', ['limit' => 20, 'null' => false, 'default' => 'comfortable'])
            ->addColumn('landing_view', 'string', ['limit' => 40, 'null' => false, 'default' => 'dashboard'])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('locale')
            ->removeColumn('week_start')
            ->removeColumn('density')
            ->removeColumn('landing_view')
            ->update();
    }
}
