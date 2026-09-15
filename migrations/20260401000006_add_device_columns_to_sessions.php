<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Enough about a session for its owner to recognise it in a list.
 *
 * The session table has held the data since phase 1; what it lacked was any way
 * for a user to tell one row from another. Address, user agent and a start time
 * are the minimum needed to answer "is that me?" — and therefore the minimum
 * needed for the revoke button beside it to mean anything.
 *
 * All three are nullable because sessions created before this migration have no
 * such detail, and a login is not the moment to start failing on a missing
 * column.
 */
final class AddDeviceColumnsToSessions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('sessions')
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('sessions')
            ->removeColumn('ip_address')
            ->removeColumn('user_agent')
            ->removeColumn('created_at')
            ->update();
    }
}
