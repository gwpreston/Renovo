<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Free-trial tracking.
 *
 * A trial is modelled as the subscription it will become, rather than as a
 * separate kind of thing. The row carries its current (usually zero) price plus
 * what it converts to, so that when the trial ends the conversion is a price
 * change on the same subscription — keeping its history, its category, its
 * tags and its identity — instead of one record ending and another beginning.
 *
 * That is also what lets the forecast see past the trial end: a trial
 * converting next month is a known future cost, and it is the cost people most
 * want warning about.
 */
final class AddTrialColumnsToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('is_trial', 'boolean', ['null' => false, 'default' => false])
            // The last day of the trial. The conversion charge falls on this
            // date: it is when the card gets taken, not the day after.
            ->addColumn('trial_end_date', 'date', ['null' => true])
            // What it becomes. Null means the trial simply ends and the
            // subscription continues at its current price.
            ->addColumn('converts_to_price_minor', 'biginteger', ['null' => true])
            ->addColumn('converts_to_billing_cycle', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('converts_to_cycle_days', 'integer', ['null' => true])
            // Finding trials about to convert, across a household.
            ->addIndex(['household_id', 'trial_end_date'], ['name' => 'ix_subscriptions_trial_end'])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeIndexByName('ix_subscriptions_trial_end')
            ->removeColumn('is_trial')
            ->removeColumn('trial_end_date')
            ->removeColumn('converts_to_price_minor')
            ->removeColumn('converts_to_billing_cycle')
            ->removeColumn('converts_to_cycle_days')
            ->update();
    }
}
