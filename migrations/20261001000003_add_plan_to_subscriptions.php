<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The tier a subscription is on — "Standard", "Family", "Premium". Free text,
 * because every provider names its plans differently.
 */
final class AddPlanToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('plan', 'string', ['limit' => 60, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('plan')
            ->update();
    }
}
