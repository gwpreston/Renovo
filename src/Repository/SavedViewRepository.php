<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\SavedView;
use App\Persistence\Database;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * A user's saved list views.
 *
 * Unscoped by household for the same reason as API tokens and notification
 * channels: the rows belong to a *user*, and every query here is already
 * narrowed to the one asking. The household id on a row is a filing detail —
 * it keeps a member of two households from being shown views built against the
 * other one's categories — not an access rule, and it is never the only thing
 * standing between one account and another's rows.
 */
final class SavedViewRepository extends AbstractRepository
{
    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'saved_views';
    }

    protected function filterableColumns(): array
    {
        return ['user_id', 'household_id'];
    }

    /**
     * @return list<SavedView>
     */
    public function findForUser(int $userId, ?int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('saved_views')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND (' . $this->quote('household_id') . ' = :household'
            . ' OR ' . $this->quote('household_id') . ' IS NULL)'
            . ' ORDER BY ' . $this->quote('position') . ' ASC, ' . $this->quote('id') . ' ASC',
            ['user' => $userId, 'household' => $householdId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function findOwnedBy(int $id, int $userId): ?SavedView
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('saved_views')
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('user_id') . ' = :user',
            ['id' => $id, 'user' => $userId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(int $userId, ?int $householdId, string $name, string $queryString): int
    {
        return $this->db->insert('saved_views', [
            'user_id' => $userId,
            'household_id' => $householdId,
            'name' => $name,
            'query_string' => $queryString,
            'position' => $this->nextPosition($userId),
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('saved_views')
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('user_id') . ' = :user',
            ['id' => $id, 'user' => $userId],
        ) > 0;
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('saved_views')
            . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );
    }

    private function nextPosition(int $userId): int
    {
        $highest = $this->db->fetchValue(
            'SELECT MAX(' . $this->quote('position') . ') FROM ' . $this->quote('saved_views')
            . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );

        return is_numeric($highest) ? ((int) $highest) + 1 : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SavedView
    {
        return new SavedView(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            householdId: isset($row['household_id']) ? (int) $row['household_id'] : null,
            name: (string) $row['name'],
            queryString: (string) ($row['query_string'] ?? ''),
            position: (int) ($row['position'] ?? 0),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
