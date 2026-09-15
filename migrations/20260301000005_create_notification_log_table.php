<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The idempotency ledger: what has already been sent, and to where.
 *
 * A scheduler that runs daily will see the same due renewal on every run until
 * the date passes. Without a record of what went out, a seven-day reminder is a
 * reminder every day for seven days, and the user turns notifications off. So
 * nothing is dispatched without first claiming a row here, and the claim is
 * enforced by a unique index rather than by a SELECT-then-INSERT that two
 * scheduler runs could interleave.
 *
 * The identity of a notification is the six columns of that index, and each is
 * load-bearing:
 *
 *  - `occurrence_key` is what makes "the same alert" mean the right thing. For
 *    a renewal it is the charge date and the lead time (`2026-10-01:7`), so
 *    re-running the scheduler sends nothing, while a payment date that actually
 *    moves is a different occurrence and does alert. Keying on the day the
 *    alert was generated instead would re-send after midnight; keying on the
 *    subscription alone would go silent forever after the first reminder.
 *  - `channel_id` is in the key because a failure on one channel must not
 *    suppress the others.
 *  - `subject_id` is 0 rather than null when an alert has no single subject —
 *    a digest, say. PostgreSQL treats nulls in a unique index as distinct from
 *    each other, so a nullable column here would quietly permit duplicates on
 *    one engine and not the other.
 *
 * `status` is what keeps a transient failure retryable. A row is claimed as
 * `pending`, becomes `sent` once the channel accepts it, and `failed` if it
 * does not. Only `sent` suppresses a later attempt; `failed` is retried until
 * the attempt count runs out, at which point it stops rather than hammering a
 * dead endpoint forever.
 */
final class CreateNotificationLogTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_log', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            ->addColumn('channel_id', 'biginteger', ['null' => false])
            ->addColumn('alert_type', 'string', ['limit' => 32, 'null' => false])
            // subscription | budget | digest | test
            ->addColumn('subject_type', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('subject_id', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('occurrence_key', 'string', ['limit' => 64, 'null' => false])
            // pending | sent | failed
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'pending'])
            ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('last_error', 'text', ['null' => true])
            ->addColumn('sent_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(
                ['user_id', 'alert_type', 'subject_type', 'subject_id', 'occurrence_key', 'channel_id'],
                ['unique' => true, 'name' => 'uq_notification_log_occurrence'],
            )
            // The outbound rate limit counts recent rows for a user, and the
            // history view lists them newest first.
            ->addIndex(['user_id', 'created_at'], ['name' => 'ix_notification_log_recent'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_notification_log_user',
            ])
            ->addForeignKey('channel_id', 'notification_channels', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_notification_log_channel',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('notification_log')->drop()->save();
    }
}
