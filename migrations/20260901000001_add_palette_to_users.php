<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The colour palette an account has chosen.
 *
 * Nullable, and null is the ordinary state: it means "the default", which is
 * navy today and is resolved in exactly one place (`App\Domain\Palette`). Not
 * defaulted to 'navy' in the column, so that if the default ever becomes an
 * instance setting, every account that never chose follows it rather than
 * being pinned to the value this migration happened to write.
 */
final class AddPaletteToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('palette', 'string', ['limit' => 20, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('palette')
            ->update();
    }
}
