<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a member wants to hear that a budget is projected over at all.
 *
 * The price-change switch's twin, defaulted on for the same reason: a budget
 * going over is money about to move, and every existing member has been told
 * about it until now. A member with no preferences row gets the same default
 * from `NotificationPreferences`.
 *
 * Turning it off silences the alert and nothing else — the budget's
 * armed/breached state is still evaluated on every run, so turning it back on
 * does not announce a breach that happened while it was off as though it were
 * new.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddBudgetAlertsToNotificationPreferences extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_preferences')
            ->addColumn('budget_alerts', 'boolean', ['null' => false, 'default' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('notification_preferences')
            ->removeColumn('budget_alerts')
            ->update();
    }
}
