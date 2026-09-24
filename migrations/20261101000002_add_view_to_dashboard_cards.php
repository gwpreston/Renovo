<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dashboard layouts, one per view.
 *
 * Overview and Household each have their own card list, reordered and hidden
 * independently, so the layout's key grows a view: hiding a card on one must
 * not hide it on the other. Every row that exists today is an Overview row —
 * there was only one dashboard — which is what the default says.
 *
 * `down()` removes the Household rows before narrowing the key again, because
 * the same card key may by then be stored under both views and the old key
 * would refuse the duplicate.
 *
 * On MySQL this is not transactional. Should it stop halfway, the manual
 * rollback is: delete the rows whose `view` is not `overview`, set the primary
 * key back to (`user_id`, `card_key`), then drop the column `view`.
 */
final class AddViewToDashboardCards extends AbstractMigration
{
    public function up(): void
    {
        $this->table('dashboard_cards')
            ->addColumn('view', 'string', ['limit' => 10, 'null' => false, 'default' => 'overview'])
            ->update();

        $this->table('dashboard_cards')
            ->changePrimaryKey(['user_id', 'view', 'card_key'])
            ->update();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM dashboard_cards WHERE view <> 'overview'");

        $this->table('dashboard_cards')
            ->changePrimaryKey(['user_id', 'card_key'])
            ->update();

        $this->table('dashboard_cards')
            ->removeColumn('view')
            ->update();
    }
}
