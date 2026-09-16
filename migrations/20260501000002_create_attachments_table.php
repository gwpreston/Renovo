<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Invoices and receipts attached to a subscription.
 *
 * `household_id` and `owner_user_id` are here for the same reason they are on
 * subscriptions: they are what the scoping layer keys on. An attachment is a
 * bank-statement-shaped document, so it inherits its subscription's isolation
 * rather than being visible to a household that cannot see what it belongs to.
 *
 * `period_date` is nullable and optional — "the March payment" — and is a date
 * rather than a foreign key because this application has no payments table. A
 * key to a row that does not exist would be a stub for a feature nobody has
 * agreed to build; a date records the same fact and costs nothing.
 *
 * `stored_path` is relative to the configured attachment directory, which lives
 * outside the web root. Nothing serves these files directly: the only way to
 * read one is the scoped streaming route.
 */
final class CreateAttachmentsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('attachments', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('owner_user_id', 'biginteger', ['null' => false])
            ->addColumn('subscription_id', 'biginteger', ['null' => false])
            ->addColumn('period_date', 'date', ['null' => true])
            ->addColumn('original_filename', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('stored_path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('size_bytes', 'biginteger', ['null' => false])
            ->addColumn('uploaded_by_user_id', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addIndex(['subscription_id'], ['name' => 'ix_attachments_subscription'])
            ->addIndex(['household_id', 'owner_user_id'], ['name' => 'ix_attachments_scope'])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_attachments_household',
            ])
            ->addForeignKey('owner_user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_attachments_owner',
            ])
            ->addForeignKey('subscription_id', 'subscriptions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_attachments_subscription',
            ])
            ->addForeignKey('uploaded_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_attachments_uploader',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('attachments')->drop()->save();
    }
}
