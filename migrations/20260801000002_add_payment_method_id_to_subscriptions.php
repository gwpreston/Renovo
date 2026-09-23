<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Which payment method a subscription is paid with, if anybody has said.
 *
 * Mirrors `category_id` exactly: nullable, indexed, and `ON DELETE SET NULL`,
 * so removing a method from the list unassigns it from its subscriptions and
 * never takes a subscription with it.
 *
 * On MySQL this is not transactional. Should it stop halfway, the manual
 * rollback is: drop the foreign key `fk_subscriptions_payment_method`, then
 * the index `ix_subscriptions_payment_method`, then the column.
 */
final class AddPaymentMethodIdToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('payment_method_id', 'biginteger', ['null' => true])
            ->addIndex(['payment_method_id'], ['name' => 'ix_subscriptions_payment_method'])
            ->addForeignKey('payment_method_id', 'payment_methods', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscriptions_payment_method',
            ])
            ->update();
    }

    public function down(): void
    {
        // The key before the index before the column: MySQL will not drop an
        // index a foreign key still depends on.
        $this->table('subscriptions')
            ->dropForeignKey('payment_method_id', 'fk_subscriptions_payment_method')
            ->update();

        $this->table('subscriptions')
            ->removeIndexByName('ix_subscriptions_payment_method')
            ->removeColumn('payment_method_id')
            ->update();
    }
}
