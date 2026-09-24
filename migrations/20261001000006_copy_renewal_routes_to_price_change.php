<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Route price-change alerts wherever renewals already go.
 *
 * Routing is stored as one row per channel per alert type, deliberately, so that
 * a new type does not quietly subscribe everybody to it. The price-change alert
 * is meant to arrive on by default, "routed like renewals", so this is the one
 * place that happens on purpose: each existing renewal route gains a sibling.
 * A member with no routing rows at all already gets every type everywhere and
 * needs nothing.
 *
 * Idempotent — a route that already exists is not copied twice, which the
 * unique index on (channel_id, alert_type) would refuse anyway.
 */
final class CopyRenewalRoutesToPriceChange extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO notification_routes (user_id, channel_id, alert_type, created_at)"
            . " SELECT r.user_id, r.channel_id, 'price_change', r.created_at"
            . " FROM notification_routes r"
            . " WHERE r.alert_type = 'renewal'"
            . " AND NOT EXISTS (SELECT 1 FROM notification_routes p"
            . " WHERE p.channel_id = r.channel_id AND p.alert_type = 'price_change')",
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM notification_routes WHERE alert_type = 'price_change'");
    }
}
