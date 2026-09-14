<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Accounts. Instance administration is a flag here rather than a role, because
 * it is orthogonal to household membership: an admin manages the instance and
 * still sees no household data unless they are a member of one.
 */
final class CreateUsersTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('users', ['id' => false, 'primary_key' => ['id']]);

        $table
            ->addColumn('id', 'biginteger', ['identity' => true])
            // Stored lower-cased by the repository so the unique index is an
            // effective case-insensitive constraint on both engines.
            ->addColumn('email', 'string', ['limit' => 254, 'null' => false])
            ->addColumn('display_name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('is_instance_admin', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('email_verified_at', 'timestamp', ['null' => true])
            ->addColumn('theme', 'string', ['limit' => 20, 'null' => false, 'default' => 'system'])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_users_email'])
            ->create();
    }

    public function down(): void
    {
        $this->table('users')->drop()->save();
    }
}
