<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Where one user's notifications go.
 *
 * Channels are per-user rather than per-household: a reminder is addressed to a
 * person, and one household's members will not share a Gotify token. A user may
 * have several of the same type — a phone and a desktop Gotify, two webhooks —
 * so the label is what distinguishes them.
 *
 * `config` is JSON because each channel type needs different fields and the
 * whole point of the notifier registry is that adding a type costs one class
 * and no migration. The notifier validates its own shape.
 *
 * It holds secrets: a Gotify application token, a Slack bot token, a webhook
 * secret. There is nowhere else they can live — they belong to a user, and
 * environment variables belong to the instance — so the rule they bend is
 * stated rather than glossed: instance secrets stay in the environment, and
 * per-user credentials the UI collects are stored here, never rendered back
 * into a form, and never written to a log.
 */
final class CreateNotificationChannelsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_channels', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            // email | gotify | slack | webhook — the notifier's key.
            ->addColumn('channel_type', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('config', 'text', ['null' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('last_error', 'text', ['null' => true])
            ->addColumn('last_success_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(['user_id'], ['name' => 'ix_notification_channels_user'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_notification_channels_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('notification_channels')->drop()->save();
    }
}
