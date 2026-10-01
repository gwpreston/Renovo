<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a recorded price is an introductory or promotional one.
 *
 * A flag beside `source` rather than a new source: `source` says why the row
 * was written, and an intro price can be the row a subscription was created
 * with as easily as one entered later. Where the offer ends is not stored —
 * it ends where the next row takes effect.
 *
 * One column added, defaulting to false so every existing row reads as an
 * ordinary price. On MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddIsPromotionalToSubscriptionPriceHistory extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscription_price_history')
            ->addColumn('is_promotional', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscription_price_history')
            ->removeColumn('is_promotional')
            ->update();
    }
}
