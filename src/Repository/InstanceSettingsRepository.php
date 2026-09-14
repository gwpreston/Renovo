<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

/**
 * Instance-wide settings, stored as a key/value table so a new setting in a
 * later phase needs no migration of its own.
 */
final class InstanceSettingsRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'instance_settings';
    }

    protected function filterableColumns(): array
    {
        return ['setting_key', 'setting_value'];
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM ' . $this->quote('instance_settings'));

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $settings;
    }

    public function get(string $key): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT ' . $this->quote('setting_value') . ' FROM ' . $this->quote('instance_settings')
            . ' WHERE ' . $this->quote('setting_key') . ' = :key',
            ['key' => $key],
        );

        return $value === null ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // Portable upsert: the engines' native syntaxes differ, and an
        // UPDATE-then-INSERT is correct on both under the single-row locking
        // this table sees.
        $updated = $this->db->execute(
            'UPDATE ' . $this->quote('instance_settings')
            . ' SET ' . $this->quote('setting_value') . ' = :value, ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('setting_key') . ' = :key',
            ['value' => $value, 'now' => $now, 'key' => $key],
        );

        if ($updated === 0) {
            $this->db->insert('instance_settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'setting_key');
        }
    }
}
