<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateHouseholdsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('households', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('created_by_user_id', 'biginteger', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addForeignKey('created_by_user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_households_created_by',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('households')->drop()->save();
    }
}
