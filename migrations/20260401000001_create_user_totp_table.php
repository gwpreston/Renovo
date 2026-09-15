<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Time-based one-time-password enrolment, one row per user.
 *
 * A row exists from the moment enrolment starts, but `confirmed_at` is what
 * makes the second factor live: a secret the user has not yet proved they can
 * generate a code from must never be allowed to lock them out of their own
 * account.
 *
 * `last_used_step` is the replay defence. A code is valid for a thirty-second
 * step and, with drift allowance, for a short window either side of it — so
 * without remembering the highest step already accepted, a code shoulder-surfed
 * or captured in transit could be presented a second time while it is still
 * arithmetically correct.
 */
final class CreateUserTotpTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_totp', ['id' => false, 'primary_key' => ['user_id']])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            // Ciphertext, not the base32 secret: see App\Security\SecretCipher.
            ->addColumn('secret', 'text', ['null' => false])
            ->addColumn('confirmed_at', 'timestamp', ['null' => true])
            ->addColumn('last_used_step', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_user_totp_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('user_totp')->drop()->save();
    }
}
