<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a subscription's cost is split, and how.
 *
 * Kept on the subscription rather than inferred from the presence of split
 * rows, because "split equally between everyone" and "split in these specific
 * proportions" behave differently when a member joins or leaves the household,
 * and because a subscription with its splits temporarily removed must not
 * silently become an un-split one.
 */
final class AddSplitModeToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            // none | equal | custom
            ->addColumn('split_mode', 'string', ['limit' => 10, 'null' => false, 'default' => 'none'])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('split_mode')
            ->update();
    }
}
