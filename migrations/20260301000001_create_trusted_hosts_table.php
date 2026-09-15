<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The administrator's allowlist of otherwise-forbidden destinations.
 *
 * The SSRF guard refuses to send a user-supplied request to a private address,
 * which is right by default and wrong for the most common self-hosted setup
 * there is: a Gotify server on the same LAN, or one reachable only over a
 * Tailscale address. This table is how an administrator says "that one is
 * mine". It is opt-in, it is per-entry, and each use of it is logged.
 *
 * An entry is a host name (`gotify.lan`), a suffix (`.lan`), a bare address or
 * a CIDR block (`100.64.0.0/10`). Entries are not validated by the database —
 * the service that writes them checks the shape, and the guard ignores anything
 * it cannot parse rather than failing open.
 */
final class CreateTrustedHostsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('trusted_hosts', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('pattern', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true])
            // Kept when the administrator's account is deleted: who added an
            // exception to the network rules outlives their membership.
            ->addColumn('created_by_user_id', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addIndex(['pattern'], ['unique' => true, 'name' => 'uq_trusted_hosts_pattern'])
            ->addForeignKey('created_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_trusted_hosts_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('trusted_hosts')->drop()->save();
    }
}
