<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The exchange-rate cache.
 *
 * Rates are instance-wide reference data, not household data: they belong to
 * nobody, reveal nothing about anybody, and every household converting GBP to
 * EUR wants the same number. So this table is deliberately outside the scoping
 * layer — its repository extends the unscoped base class.
 *
 * `rate_scaled` is an integer: the rate multiplied by 10^8 (RATE_SCALE_EXPONENT
 * in App\Domain\ExchangeRate). A DECIMAL column would work too, but it would
 * come back from PDO as a string that something would eventually cast to a
 * float, and floats are how currency bugs get in. Eight decimal places is
 * comfortably more precision than any published daily rate carries.
 */
final class CreateExchangeRatesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('exchange_rates', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('base_currency', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('quote_currency', 'char', ['limit' => 3, 'null' => false])
            // The rate, times 10^8. 1 GBP = 1.17234500 EUR is stored as
            // 117234500 with base GBP and quote EUR.
            ->addColumn('rate_scaled', 'biginteger', ['null' => false])
            // Which provider supplied it, so a provider change can invalidate
            // rates that came from the previous one.
            ->addColumn('provider', 'string', ['limit' => 40, 'null' => false])
            // The date the provider says the rate is for, which is not the same
            // as when we fetched it — most free feeds publish once a day.
            ->addColumn('as_of_date', 'date', ['null' => true])
            ->addColumn('fetched_at', 'timestamp', ['null' => false])
            ->addIndex(
                ['base_currency', 'quote_currency'],
                ['unique' => true, 'name' => 'uq_exchange_rates_pair'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('exchange_rates')->drop()->save();
    }
}
