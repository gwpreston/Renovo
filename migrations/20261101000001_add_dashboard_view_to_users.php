<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Which of the two dashboards a user opens on.
 *
 * Null means Overview, so every existing account keeps the dashboard it has
 * without a backfill, and the default can be stated once, in code, rather than
 * in every row. Ten characters is room for the two views and a third.
 *
 * On MySQL this is not transactional. Should it stop halfway, the manual
 * rollback is: drop the column `users.dashboard_view`.
 */
final class AddDashboardViewToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('dashboard_view', 'string', ['limit' => 10, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('dashboard_view')
            ->update();
    }
}
