<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The central table.
 *
 * Two columns carry the isolation model: `household_id` is always present, and
 * `owner_user_id` records which member the cost belongs to. The scoping layer
 * filters on the first always and on the second when the instance is ISOLATED.
 * `payer_user_id` is who actually pays and has no visibility meaning.
 *
 * The price is an integer number of minor units. There is no float column in
 * this schema and there never will be.
 */
final class CreateSubscriptionsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('owner_user_id', 'biginteger', ['null' => false])
            ->addColumn('payer_user_id', 'biginteger', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('notes', 'text', ['null' => true])
            // Minor units: 999 means £9.99 in GBP and ¥999 in JPY.
            ->addColumn('price_minor', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('currency', 'char', ['limit' => 3, 'null' => false])
            // recurring | one_off | lifetime
            ->addColumn('subscription_type', 'string', ['limit' => 20, 'null' => false, 'default' => 'recurring'])
            // weekly | monthly | quarterly | yearly | custom_days; null for
            // one-off and lifetime entries, which do not recur.
            ->addColumn('billing_cycle', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('cycle_days', 'integer', ['null' => true])
            ->addColumn('next_payment_date', 'date', ['null' => true])
            ->addColumn('start_date', 'date', ['null' => true])
            // The day-of-month a calendar cycle should return to after a short
            // month has forced it earlier.
            ->addColumn('anchor_day', 'integer', ['null' => true])
            ->addColumn('notice_period_amount', 'integer', ['null' => true])
            // days | weeks | months
            ->addColumn('notice_period_unit', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('logo_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('category_id', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            // The index the scoping layer's predicate uses on every read.
            ->addIndex(['household_id', 'owner_user_id'], ['name' => 'ix_subscriptions_scope'])
            ->addIndex(['household_id', 'next_payment_date'], ['name' => 'ix_subscriptions_next_payment'])
            ->addIndex(['household_id', 'is_active'], ['name' => 'ix_subscriptions_active'])
            ->addIndex(['category_id'], ['name' => 'ix_subscriptions_category'])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscriptions_household',
            ])
            ->addForeignKey('owner_user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscriptions_owner',
            ])
            ->addForeignKey('payer_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscriptions_payer',
            ])
            ->addForeignKey('category_id', 'categories', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscriptions_category',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('subscriptions')->drop()->save();
    }
}
