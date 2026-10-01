<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The interval a trial's converts-to cycle runs on — a trial that becomes a
 * two-monthly plan. Nullable like its siblings: null means the trial converts
 * to the cycle it already has.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddConvertsToCycleIntervalToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('converts_to_cycle_interval', 'smallinteger', [
                'null' => true,
                'default' => null,
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('converts_to_cycle_interval')
            ->update();
    }
}
