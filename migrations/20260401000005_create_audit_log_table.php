<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Who did what, to whom, from where.
 *
 * Two decisions here are worth the words:
 *
 * `actor_label` and `target_label` hold the address or name as it was at the
 * time, beside the foreign keys. An audit trail that loses its meaning when an
 * account is deleted is not an audit trail, so the keys are nullable and set to
 * null on delete while the labels stay.
 *
 * `household_id` is nullable and it is what makes the read side scopable. An
 * instance administrator sees everything; a household Owner sees the events
 * belonging to their household and nothing else. Without the column that
 * distinction could only be drawn by a route remembering to add a condition,
 * which is precisely the arrangement the scoping layer exists to prevent.
 */
final class CreateAuditLogTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('audit_log', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('occurred_at', 'timestamp', ['null' => false])
            ->addColumn('action', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('actor_user_id', 'biginteger', ['null' => true])
            ->addColumn('actor_label', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('target_user_id', 'biginteger', ['null' => true])
            ->addColumn('target_label', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('household_id', 'biginteger', ['null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            // JSON-encoded detail. Text rather than a json column type so the
            // same migration applies to both engines without branching.
            ->addColumn('context', 'text', ['null' => true])
            ->addIndex(['occurred_at'], ['name' => 'ix_audit_log_occurred'])
            ->addIndex(['household_id', 'occurred_at'], ['name' => 'ix_audit_log_household'])
            ->addIndex(['actor_user_id'], ['name' => 'ix_audit_log_actor'])
            ->addIndex(['action'], ['name' => 'ix_audit_log_action'])
            ->addForeignKey('actor_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_audit_log_actor',
            ])
            ->addForeignKey('target_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_audit_log_target',
            ])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_audit_log_household',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('audit_log')->drop()->save();
    }
}
