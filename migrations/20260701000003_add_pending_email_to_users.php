<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The address a member has asked to move to, but has not yet proved.
 *
 * Changing an email address is the one account change that can lock somebody
 * out of their own account, or hand it to somebody else: write the new address
 * straight into `email` and a typo makes the account unreachable, while an
 * address the member does not control makes it reachable by whoever does. So
 * the new value waits here, `email` stays the login, and the two swap only when
 * a token sent *to the new address* comes back.
 *
 * Deliberately not unique. Two accounts may have the same address pending
 * without either of them having proved it, and a unique index would let one
 * member's unconfirmed request block another's — an easy denial of service
 * against any address you can guess. Uniqueness is enforced where it matters:
 * against `email` at request time and again at confirmation time, by which
 * point the address has been proved.
 */
final class AddPendingEmailToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('pending_email', 'string', ['limit' => 254, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('pending_email')
            ->update();
    }
}
