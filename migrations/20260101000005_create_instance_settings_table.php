<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Key/value instance settings, so a new setting in a later phase costs no
 * migration.
 */
final class CreateInstanceSettingsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('instance_settings', ['id' => false, 'primary_key' => ['setting_key']])
            ->addColumn('setting_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('setting_value', 'text', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->create();
    }

    public function down(): void
    {
        $this->table('instance_settings')->drop()->save();
    }
}
