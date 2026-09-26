<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Who a subscription is visible to: the household (the default, and what every
 * existing row becomes) or only the member who pays it.
 *
 * A string rather than a boolean so that the value names itself in a query and
 * in a backup, and so that the scoping layer's clause reads as the rule it is —
 * `visibility = 'household' OR owner_user_id = :viewer`.
 */
final class AddVisibilityToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('visibility', 'string', ['limit' => 10, 'null' => false, 'default' => 'household'])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('visibility')
            ->update();
    }
}
