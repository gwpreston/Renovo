<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Which alert types go to which channel.
 *
 * A table rather than a JSON column on the preferences row, because every entry
 * points at a channel and a foreign key is what stops a deleted channel leaving
 * a route to nowhere.
 *
 * The absence of rows is meaningful and is the default: a user who has never
 * touched routing gets every alert type on every active channel. Anything else
 * would mean adding a channel and then silently receiving nothing on it until
 * you found a second screen.
 */
final class CreateNotificationRoutesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_routes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            ->addColumn('channel_id', 'biginteger', ['null' => false])
            // renewal | trial_conversion | cancel_by | budget_exceeded
            ->addColumn('alert_type', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addIndex(['user_id', 'alert_type'], ['name' => 'ix_notification_routes_user'])
            ->addIndex(
                ['channel_id', 'alert_type'],
                ['unique' => true, 'name' => 'uq_notification_routes_channel_alert'],
            )
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_notification_routes_user',
            ])
            ->addForeignKey('channel_id', 'notification_channels', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_notification_routes_channel',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('notification_routes')->drop()->save();
    }
}
