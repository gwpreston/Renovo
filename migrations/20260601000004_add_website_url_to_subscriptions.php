<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Where a subscription is managed, as a URL.
 *
 * It earns its place twice over: it is the link somebody wants when they have
 * decided to cancel something, and it is the only honest source for a logo.
 * Guessing a domain from a subscription's name would be wrong often and wrong
 * invisibly — "Sky" is not sky.com to everybody — and asking a third-party
 * icon service would mean sending this household's list of subscriptions to a
 * stranger.
 */
final class AddWebsiteUrlToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('website_url', 'string', ['limit' => 300, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('website_url')
            ->update();
    }
}
