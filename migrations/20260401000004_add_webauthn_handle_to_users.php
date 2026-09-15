<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The opaque user handle WebAuthn credentials are bound to.
 *
 * Deliberately not the primary key. The handle is stored inside the
 * authenticator and is handed back by the browser during a usernameless login,
 * including to any site the credential is offered to, so using the row id would
 * publish a guessable, enumerable identifier for the account. A random handle
 * discloses nothing and can be rotated if it ever needs to be.
 *
 * Nullable: it is generated when a user registers their first credential, so
 * accounts that never use a passkey never acquire one.
 */
final class AddWebauthnHandleToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('webauthn_handle', 'string', [
                'limit' => 64,
                'null' => true,
            ])
            ->addIndex(['webauthn_handle'], ['unique' => true, 'name' => 'uq_users_webauthn_handle'])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeIndexByName('uq_users_webauthn_handle')
            ->removeColumn('webauthn_handle')
            ->update();
    }
}
