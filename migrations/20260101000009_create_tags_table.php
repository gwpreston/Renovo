<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTagsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tags', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(['household_id', 'name'], ['unique' => true, 'name' => 'uq_tags_household_name'])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_tags_household',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('tags')->drop()->save();
    }
}
