<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * How many weeks, months or years one billing cycle spans: a six-monthly
 * insurance policy is monthly with an interval of 6.
 *
 * Defaulted to 1, which is what every existing row already means — weekly is
 * every week, monthly every month — so there is no data step and no figure
 * moves. Quarterly and custom-day cycles always hold 1.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddCycleIntervalToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('cycle_interval', 'smallinteger', ['null' => false, 'default' => 1])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('cycle_interval')
            ->update();
    }
}
