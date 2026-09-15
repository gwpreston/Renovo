<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A per-subscription override of the user's reminder lead times.
 *
 * Null — the default, and what every existing row gets — means "use my
 * preference". A value such as "60,14" is for the handful of things where the
 * general rule does not fit: an annual insurance policy worth a two-month
 * warning, a cheap monthly one worth none at all (an empty string, which is a
 * deliberate "never remind me about this" rather than a missing value).
 */
final class AddReminderDaysToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('reminder_days', 'string', ['limit' => 100, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('reminder_days')
            ->update();
    }
}
