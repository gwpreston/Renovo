<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Login and reset attempts, the basis of the per-account and per-IP throttles.
 *
 * The account key is a hash rather than the address itself: the throttle needs
 * to count attempts, not to keep a list of which addresses have accounts.
 */
final class CreateAuthAttemptsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('auth_attempts', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            // login | reset
            ->addColumn('kind', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('account_key', 'string', ['limit' => 64, 'null' => false])
            // Long enough for an IPv6 address with a zone index.
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => false])
            ->addColumn('successful', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('attempted_at', 'timestamp', ['null' => false])
            ->addIndex(['kind', 'account_key', 'attempted_at'], ['name' => 'ix_auth_attempts_account'])
            ->addIndex(['kind', 'ip_address', 'attempted_at'], ['name' => 'ix_auth_attempts_ip'])
            ->create();
    }

    public function down(): void
    {
        $this->table('auth_attempts')->drop()->save();
    }
}
