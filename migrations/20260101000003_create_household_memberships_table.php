<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The join between a user and a household, carrying the role that decides what
 * they may do inside it.
 */
final class CreateHouseholdMembershipsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('household_memberships', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            // owner_admin | editor | viewer
            ->addColumn('role', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(['household_id', 'user_id'], [
                'unique' => true,
                'name' => 'uq_memberships_household_user',
            ])
            ->addIndex(['user_id'], ['name' => 'ix_memberships_user'])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_memberships_household',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_memberships_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('household_memberships')->drop()->save();
    }
}
