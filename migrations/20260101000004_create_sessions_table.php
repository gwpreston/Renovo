<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Server-side session storage, so the app container holds no state of its own.
 */
final class CreateSessionsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('sessions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('user_id', 'biginteger', ['null' => true])
            ->addColumn('payload', 'text', ['null' => false])
            ->addColumn('last_activity', 'timestamp', ['null' => false])
            ->addColumn('expires_at', 'timestamp', ['null' => false])
            ->addIndex(['expires_at'], ['name' => 'ix_sessions_expires_at'])
            ->addIndex(['user_id'], ['name' => 'ix_sessions_user'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_sessions_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('sessions')->drop()->save();
    }
}
