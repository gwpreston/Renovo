<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a member wants to hear about price changes at all.
 *
 * Defaulted on, for existing rows and new ones alike: a price rise is money
 * about to move, which is what every other alert is for. A member with no
 * preferences row gets the same default from `NotificationPreferences`.
 */
final class AddPriceChangeAlertsToNotificationPreferences extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_preferences')
            ->addColumn('price_change_alerts', 'boolean', ['null' => false, 'default' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('notification_preferences')
            ->removeColumn('price_change_alerts')
            ->update();
    }
}
