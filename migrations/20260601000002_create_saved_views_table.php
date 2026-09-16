<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A named set of list filters, belonging to one user.
 *
 * `query_string` is what the subscriptions list already puts in the address
 * bar. Storing that rather than a column per filter is deliberate: the filter
 * is parsed by one value object, `SubscriptionFilter::fromQueryParams()`, which
 * already validates every part of it against an allow-list — so a saved view is
 * re-read through exactly the code that reads a link somebody pasted, and a
 * tampered row can no more reach the SQL than a tampered URL can.
 *
 * Per user rather than per household: a view is how one person likes to look at
 * the list, not a fact about the household's data. It carries the household it
 * was saved in so that a member of two households does not see the other one's
 * categories offered in this one's filters.
 */
final class CreateSavedViewsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('saved_views', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            ->addColumn('household_id', 'biginteger', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('query_string', 'string', ['limit' => 500, 'null' => false, 'default' => ''])
            ->addColumn('position', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addIndex(['user_id', 'household_id'], ['name' => 'ix_saved_views_user'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_saved_views_user',
            ])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_saved_views_household',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('saved_views')->drop()->save();
    }
}
