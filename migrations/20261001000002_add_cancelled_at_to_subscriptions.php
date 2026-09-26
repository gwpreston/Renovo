<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The day a subscription was cancelled, when it has been.
 *
 * A cancelled subscription is finished, where a paused one may resume, and the
 * difference is this date. Indexed because the list's status filter and every
 * catch-up query ask about it.
 *
 * On MySQL this is not transactional. Should it stop halfway, the manual
 * rollback is: drop the index `ix_subscriptions_cancelled_at`, then the column.
 */
final class AddCancelledAtToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('cancelled_at', 'date', ['null' => true])
            ->addIndex(['cancelled_at'], ['name' => 'ix_subscriptions_cancelled_at'])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeIndexByName('ix_subscriptions_cancelled_at')
            ->removeColumn('cancelled_at')
            ->update();
    }
}
