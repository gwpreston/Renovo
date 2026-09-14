<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * A small amount of reference data for a fresh development database.
 *
 * Deliberately limited to categories, and only for a household that already
 * exists: seeding users would mean seeding password hashes, and an instance
 * that ships with a known account is a bad default even in development. Create
 * the first account through the setup wizard.
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

        $existing = $this->fetchRow(sprintf(
            'SELECT COUNT(*) AS total FROM categories WHERE household_id = %d',
            (int) $household['id'],
        ));

        if ($existing !== false && (int) ($existing['total'] ?? 0) > 0) {
            $this->output->writeln('<comment>Categories already present — nothing seeded.</comment>');

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
                'household_id' => (int) $household['id'],
                'name' => $name,
                'colour' => $colour,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->table('categories')->insert($rows)->saveData();
    }
}
