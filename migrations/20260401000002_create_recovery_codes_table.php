<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Single-use codes that get a user back in when their second factor is gone.
 *
 * Not tied to any one factor, which is why the table is not named after one: an
 * account whose only second factor is a passkey needs the same way back in as
 * one using an authenticator app, and on a self-hosted instance there is no
 * support desk to appeal to. A second factor without a recovery path is a way
 * to lose an account permanently.
 *
 * The codes are hashed exactly like passwords — a leaked backup of this table
 * is not a set of working credentials — and each one is marked used rather than
 * deleted, so the audit log's "recovery code used" entry has something to point
 * at.
 */
final class CreateRecoveryCodesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('recovery_codes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            ->addColumn('code_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('used_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id'], ['name' => 'ix_recovery_codes_user'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_recovery_codes_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('recovery_codes')->drop()->save();
    }
}
