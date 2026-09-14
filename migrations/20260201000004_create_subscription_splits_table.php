<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shared-cost splitting.
 *
 * One row per participant, holding an integer weight rather than a percentage
 * or an amount. Weights avoid both of the usual failure modes: percentages that
 * do not add to a hundred, and stored amounts that stop adding up to the price
 * the moment the price changes. Each member's actual share is derived from the
 * current price and the weights whenever it is needed, and the derivation
 * allocates the remainder so the shares always sum to the price exactly.
 *
 * `household_id` and `owner_user_id` mirror the subscription's, so the table
 * passes through the scoping layer natively. `user_id` is the participant, and
 * it is a different thing entirely: it is what lets a member see a subscription
 * they help pay for but do not own, when the instance is ISOLATED. That
 * widening is applied by the scoping layer's read predicate alone — being
 * listed here grants visibility, never the ability to change anything.
 */
final class CreateSubscriptionSplitsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscription_splits', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('subscription_id', 'biginteger', ['null' => false])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('owner_user_id', 'biginteger', ['null' => false])
            // The member who pays this share.
            ->addColumn('user_id', 'biginteger', ['null' => false])
            // Relative weight. An equal split gives every participant 1; a
            // custom split gives whatever the household agreed.
            ->addColumn('share_units', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            // A member appears at most once per subscription.
            ->addIndex(
                ['subscription_id', 'user_id'],
                ['unique' => true, 'name' => 'uq_subscription_splits_member'],
            )
            // The lookup behind the read-visibility widening: "which
            // subscriptions is this user a participant in?"
            ->addIndex(['user_id', 'household_id'], ['name' => 'ix_subscription_splits_participant'])
            ->addIndex(['household_id', 'owner_user_id'], ['name' => 'ix_subscription_splits_scope'])
            ->addForeignKey('subscription_id', 'subscriptions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscription_splits_subscription',
            ])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscription_splits_household',
            ])
            ->addForeignKey('owner_user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscription_splits_owner',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscription_splits_member',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('subscription_splits')->drop()->save();
    }
}
