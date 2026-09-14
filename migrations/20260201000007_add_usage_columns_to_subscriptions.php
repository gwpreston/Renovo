<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The "is this worth it?" signal.
 *
 * A counter and an optional rating rather than a table of individual usage
 * events. The question this answers is "am I paying a lot for something I
 * barely touch", and a running count answers it as well as a log would while
 * asking far less of the user — an event table nobody fills in is worse than a
 * counter somebody occasionally bumps.
 *
 * `usage_counted_since` is what makes the counter mean anything. Forty uses is
 * excellent over a month and poor over three years, so the count is always
 * interpreted against the date it started from, and resetting the counter moves
 * that date.
 */
final class AddUsageColumnsToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('usage_count', 'integer', ['null' => false, 'default' => 0])
            // 1-5, entirely optional. Some things are not worth counting but
            // are easy to have an opinion about.
            ->addColumn('usage_rating', 'integer', ['null' => true])
            ->addColumn('usage_counted_since', 'date', ['null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('usage_count')
            ->removeColumn('usage_rating')
            ->removeColumn('usage_counted_since')
            ->update();
    }
}
