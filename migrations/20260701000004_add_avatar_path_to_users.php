<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Where a member's picture is filed, relative to the avatar directory.
 *
 * A path rather than the bytes: an image in a column is read back on every
 * request that renders a face, and there are several per page. The directory
 * sits outside the web root and the file is served by a scoped route, so this
 * value is never a URL — see AvatarStorage for why a picture of a child is not
 * a public-ish asset like a subscription's logo.
 *
 * Nullable, and null is the ordinary state: an account with no picture renders
 * its initials, which needs no storage at all.
 */
final class AddAvatarPathToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('avatar_path', 'string', ['limit' => 255, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('avatar_path')
            ->update();
    }
}
