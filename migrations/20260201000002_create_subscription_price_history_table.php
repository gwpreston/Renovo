<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Price history — append-only.
 *
 * A row is never updated and never deleted except with its subscription. What
 * a subscription cost last March is a fact, and a schema that lets it be
 * rewritten is a schema that will eventually lose it. "The price went up" is
 * therefore a new row with a later effective date, not an edit.
 *
 * The same shape carries scheduled increases: a row whose `effective_from` is
 * in the future is a price change that has been announced but has not happened
 * yet. Current price is the latest row that has taken effect; the next
 * scheduled change is the earliest row that has not.
 *
 * `household_id` and `owner_user_id` are denormalised from the subscription so
 * that this table goes through the scoping layer natively rather than relying
 * on a join to stay correct — the isolation predicate applies to it exactly as
 * it does to subscriptions.
 */
final class CreateSubscriptionPriceHistoryTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscription_price_history', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('subscription_id', 'biginteger', ['null' => false])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('owner_user_id', 'biginteger', ['null' => false])
            ->addColumn('price_minor', 'biginteger', ['null' => false])
            ->addColumn('currency', 'char', ['limit' => 3, 'null' => false])
            // The date this price started, or starts, applying.
            ->addColumn('effective_from', 'date', ['null' => false])
            // How the row came about: 'initial', 'manual', 'scheduled',
            // 'trial_conversion', 'currency_change'. Kept so the trend view can
            // explain a change rather than just showing a step.
            ->addColumn('source', 'string', ['limit' => 20, 'null' => false, 'default' => 'manual'])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_by_user_id', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            // Resolving the current price means "the latest effective row for
            // this subscription", which is exactly this index.
            ->addIndex(
                ['subscription_id', 'effective_from'],
                ['name' => 'ix_price_history_subscription_effective'],
            )
            ->addIndex(['household_id', 'owner_user_id'], ['name' => 'ix_price_history_scope'])
            // Finding scheduled changes that are now due, across a household.
            ->addIndex(['household_id', 'effective_from'], ['name' => 'ix_price_history_due'])
            ->addForeignKey('subscription_id', 'subscriptions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_price_history_subscription',
            ])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_price_history_household',
            ])
            ->addForeignKey('owner_user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_price_history_owner',
            ])
            ->addForeignKey('created_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_price_history_author',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('subscription_price_history')->drop()->save();
    }
}
