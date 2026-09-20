<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Two account-level states an administrator can put a member into.
 *
 * `disabled_at` is revoked login. Non-null means the account cannot
 * authenticate by any route — password, passkey, second factor or API token —
 * and the timestamp rather than a boolean because "since when" is the first
 * question anybody asks about a disabled account. It is distinct from removing
 * somebody from a household: a removed member keeps their account and their
 * own data, a disabled one keeps their membership and cannot get in.
 *
 * `must_change_password` exists for the one case the invite flow cannot serve:
 * a member with no mailbox of their own — a child on a family instance — whose
 * account the Owner creates with a one-time temporary password. The password
 * is shown to the admin once and stored only as a hash like any other, and
 * this flag is what makes it temporary: every authenticated request is diverted
 * to the change-password screen until the member has set one of their own.
 * Default false, so no existing account is affected and the ordinary
 * email-invite path never sets it.
 */
final class AddAccountStateToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('disabled_at', 'timestamp', ['null' => true])
            ->addColumn('must_change_password', 'boolean', ['null' => false, 'default' => false])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('disabled_at')
            ->removeColumn('must_change_password')
            ->update();
    }
}
