<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Budgets.
 *
 * A budget belongs to a member (`owner_user_id`) and measures that member's own
 * share of spending — their subscriptions plus their portion of anything split.
 * That is the plain reading of "each member's budget counts only their share",
 * and it is what makes budgets behave sensibly in both isolation modes: in
 * SHARED the household can see each other's, in ISOLATED they cannot, and in
 * neither case does one member's budget silently include another's spending.
 *
 * `category_id` null means the budget covers everything.
 */
final class CreateBudgetsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('budgets', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('owner_user_id', 'biginteger', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            // Null = an overall budget rather than a per-category one.
            ->addColumn('category_id', 'biginteger', ['null' => true])
            // monthly | annual
            ->addColumn('period', 'string', ['limit' => 10, 'null' => false, 'default' => 'monthly'])
            ->addColumn('amount_minor', 'biginteger', ['null' => false])
            ->addColumn('currency', 'char', ['limit' => 3, 'null' => false])
            // Optional early warning, as a percentage of the amount. 90 means
            // "tell me once I am projected to reach nine tenths of it".
            ->addColumn('warn_threshold_percent', 'integer', ['null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(['household_id', 'owner_user_id'], ['name' => 'ix_budgets_scope'])
            ->addIndex(['category_id'], ['name' => 'ix_budgets_category'])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_budgets_household',
            ])
            ->addForeignKey('owner_user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_budgets_owner',
            ])
            ->addForeignKey('category_id', 'categories', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_budgets_category',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('budgets')->drop()->save();
    }
}
