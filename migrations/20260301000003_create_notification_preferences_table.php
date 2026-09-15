<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One row per user: when they want to hear, and in what form.
 *
 * `lead_days` is the list of reminders before a charge — "30,7,1" means three
 * separate notifications, not one. It is the default for every subscription;
 * a subscription may override it with its own list.
 *
 * `digest_mode` changes the shape rather than the content. In digest mode the
 * alerts are not sent as they come due — they are collected and sent together
 * on the chosen day, and the lead times stop applying, because a weekly summary
 * that also fired three separate reminders would be the worst of both.
 *
 * `digest_day` is read against the mode: 1-7 (Monday first) when weekly, 1-28
 * when monthly. The cap at 28 is not fussiness — a digest set for the 30th
 * would silently skip February.
 */
final class CreateNotificationPreferencesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_preferences', ['id' => false, 'primary_key' => ['user_id']])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            ->addColumn('lead_days', 'string', ['limit' => 100, 'null' => false, 'default' => '30,7,1'])
            // immediate | weekly | monthly
            ->addColumn('digest_mode', 'string', ['limit' => 16, 'null' => false, 'default' => 'immediate'])
            ->addColumn('digest_day', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_notification_preferences_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('notification_preferences')->drop()->save();
    }
}
