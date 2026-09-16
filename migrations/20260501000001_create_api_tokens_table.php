<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bearer tokens for the versioned API and the calendar feed.
 *
 * Three columns carry the design.
 *
 * `public_id` is the half of the token that identifies it, and `token_hash` is
 * a SHA-256 of the half that proves it. Splitting them is what lets a lookup be
 * an indexed read of one row rather than a scan hashing every candidate, while
 * a leaked backup still yields nothing usable. The secret is never stored.
 *
 * `household_id` pins the token to the household that was selected when it was
 * issued. A user who belongs to two households would otherwise have a token
 * whose meaning changed depending on what they last clicked in the browser,
 * which is not a property an unattended script should have to reason about.
 *
 * `abilities` is read or write, and it can only ever narrow. The scope built
 * for a request still comes from the bearer's membership, so a read token held
 * by an Owner reads exactly what that Owner may read and writes nothing — the
 * token cannot grant what the role does not.
 */
final class CreateApiTokensTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('api_tokens', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            ->addColumn('household_id', 'biginteger', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('public_id', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('abilities', 'string', ['limit' => 16, 'null' => false, 'default' => 'read'])
            ->addColumn('last_used_at', 'timestamp', ['null' => true])
            ->addColumn('expires_at', 'timestamp', ['null' => true])
            ->addColumn('revoked_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addIndex(['public_id'], ['unique' => true, 'name' => 'ux_api_tokens_public_id'])
            ->addIndex(['user_id'], ['name' => 'ix_api_tokens_user'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_api_tokens_user',
            ])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_api_tokens_household',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('api_tokens')->drop()->save();
    }
}
