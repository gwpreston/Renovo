<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Single-use tokens for email verification and password reset.
 *
 * Only the SHA-256 of each token is stored, so a copy of this table is not a
 * set of working links.
 */
final class CreateAuthTokensTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('auth_tokens', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            // verify_email | reset_password
            ->addColumn('purpose', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'timestamp', ['null' => false])
            ->addColumn('consumed_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_auth_tokens_hash'])
            ->addIndex(['user_id', 'purpose'], ['name' => 'ix_auth_tokens_user_purpose'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_auth_tokens_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('auth_tokens')->drop()->save();
    }
}
