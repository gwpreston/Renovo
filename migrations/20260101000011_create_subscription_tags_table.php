<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSubscriptionTagsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscription_tags', ['id' => false, 'primary_key' => ['subscription_id', 'tag_id']])
            ->addColumn('subscription_id', 'biginteger', ['null' => false])
            ->addColumn('tag_id', 'biginteger', ['null' => false])
            ->addIndex(['tag_id'], ['name' => 'ix_subscription_tags_tag'])
            ->addForeignKey('subscription_id', 'subscriptions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscription_tags_subscription',
            ])
            ->addForeignKey('tag_id', 'tags', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_subscription_tags_tag',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('subscription_tags')->drop()->save();
    }
}
