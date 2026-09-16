<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Which dashboard cards a user shows, and in what order.
 *
 * A table rather than a column, because this is the one display preference
 * that is a list. The alternative — a comma-separated column, or JSON — would
 * either need string surgery on every read or the JSON operators that are the
 * clearest difference between the two engines this application supports.
 *
 * A user with no rows here gets the default layout. Rows appear only once
 * somebody has rearranged something, so the table stays empty on most
 * instances and the defaults can change in a later version without a migration
 * rewriting everybody's choices.
 */
final class CreateDashboardCardsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('dashboard_cards', ['id' => false, 'primary_key' => ['user_id', 'card_key']])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            ->addColumn('card_key', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('position', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('is_visible', 'boolean', ['null' => false, 'default' => true])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_dashboard_cards_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('dashboard_cards')->drop()->save();
    }
}
