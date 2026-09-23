<?php

declare(strict_types=1);

use App\Domain\DefaultPaymentMethods;
use Phinx\Seed\AbstractSeed;

/**
 * A small amount of reference data for a fresh development database.
 *
 * Deliberately limited to categories and payment methods, and only for a
 * household that already exists: seeding users would mean seeding password
 * hashes, and an instance that ships with a known account is a bad default
 * even in development. Create the first account through the setup wizard.
 *
 * Payment methods are normally given to a household when it is created, so
 * this only adds them to one that predates them. It cannot assign them to
 * anything — it creates no subscriptions — which is what
 * `bin/dev-setup.sh --with-sample-data` is for.
 */
class DemoDataSeeder extends AbstractSeed
{
    public function run(): void
    {
        $household = $this->fetchRow('SELECT id FROM households ORDER BY id ASC');

        if ($household === false || !isset($household['id'])) {
            $this->output->writeln(
                '<comment>No household found — run the setup wizard first, then re-run this seed.</comment>',
            );

            return;
        }

        $householdId = (int) $household['id'];

        $this->seedCategories($householdId);
        $this->seedPaymentMethods($householdId);
    }

    private function seedCategories(int $householdId): void
    {
        if ($this->countFor('categories', $householdId) > 0) {
            $this->output->writeln('<comment>Categories already present — none seeded.</comment>');

            return;
        }

        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach (
            [
            'Streaming' => '#e0516b',
            'Music' => '#4f7df0',
            'Software' => '#48a97c',
            'Utilities' => '#d4a03c',
            'Insurance' => '#8b6bd9',
            'Health' => '#3fa9c9',
            ] as $name => $colour
        ) {
            $rows[] = [
                'household_id' => $householdId,
                'name' => $name,
                'colour' => $colour,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->table('categories')->insert($rows)->saveData();
    }

    /**
     * The same defaults a new household is given, named from the base
     * catalogue — a seed runs outside the application, with no locale to ask.
     */
    private function seedPaymentMethods(int $householdId): void
    {
        if ($this->countFor('payment_methods', $householdId) > 0) {
            $this->output->writeln('<comment>Payment methods already present — none seeded.</comment>');

            return;
        }

        /** @var array<string, string> $catalogue */
        $catalogue = require dirname(__DIR__) . '/translations/en.php';
        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach (DefaultPaymentMethods::all() as $default) {
            $rows[] = [
                'household_id' => $householdId,
                'name' => $catalogue[$default['key']],
                'icon' => $default['icon'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->table('payment_methods')->insert($rows)->saveData();
    }

    private function countFor(string $table, int $householdId): int
    {
        $row = $this->fetchRow(
            sprintf('SELECT COUNT(*) AS total FROM %s WHERE household_id = %d', $table, $householdId),
        );

        return $row === false ? 0 : (int) ($row['total'] ?? 0);
    }
}
