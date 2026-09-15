<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Registered passkeys / security keys.
 *
 * `credential_record` is the library's own serialisation and is the source of
 * truth used to verify an assertion; the columns beside it are duplicated out
 * of it so that a lookup by credential id, a list in the UI or a sign-count
 * update does not mean deserialising every row. `sign_count` is kept current
 * because a counter that goes backwards is the signal that a credential has
 * been cloned.
 *
 * A user may hold several: that is the point of the table having its own id
 * rather than hanging one credential off the user row. Naming and revoking are
 * per credential, so losing one key does not mean starting again.
 */
final class CreateWebauthnCredentialsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('webauthn_credentials', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            // Base64url of the raw credential id. Indexed unique because the
            // authenticator, not this server, chooses it.
            ->addColumn('credential_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('credential_record', 'text', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('aaguid', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('transports', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('sign_count', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('is_discoverable', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('last_used_at', 'timestamp', ['null' => true])
            ->addIndex(['credential_id'], ['unique' => true, 'name' => 'uq_webauthn_credential_id'])
            ->addIndex(['user_id'], ['name' => 'ix_webauthn_user'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_webauthn_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('webauthn_credentials')->drop()->save();
    }
}
