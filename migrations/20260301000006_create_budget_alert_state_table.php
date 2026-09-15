<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a budget is currently in breach, so that it is announced once.
 *
 * Budget windows roll from today rather than sitting on calendar boundaries,
 * so there is no month-end at which "one alert per period" could reset itself.
 * The dedup is therefore a state machine and this table is its memory: a budget
 * is armed until its projection first exceeds the limit, which fires the alert
 * and disarms it, and it re-arms only when the projection drops back under.
 * Twelve consecutive scheduler runs over the limit produce one notification.
 *
 * The scope columns are here because this is household data and the repository
 * that reads it is a scoped one. A budget belongs to a member, and so does the
 * knowledge of whether they are over it.
 */
final class CreateBudgetAlertStateTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('budget_alert_state', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('owner_user_id', 'biginteger', ['null' => false])
            ->addColumn('budget_id', 'biginteger', ['null' => false])
            ->addColumn('is_breached', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('projected_minor', 'biginteger', ['null' => true])
            ->addColumn('last_alert_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(['budget_id'], ['unique' => true, 'name' => 'uq_budget_alert_state_budget'])
            ->addIndex(['household_id', 'owner_user_id'], ['name' => 'ix_budget_alert_state_scope'])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_budget_alert_state_household',
            ])
            ->addForeignKey('owner_user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_budget_alert_state_owner',
            ])
            ->addForeignKey('budget_id', 'budgets', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_budget_alert_state_budget',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('budget_alert_state')->drop()->save();
    }
}
