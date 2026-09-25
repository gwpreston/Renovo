<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\Tag;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

final class TagRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'tags';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'household_id', 'name'];
    }

    protected function ownerColumn(): ?string
    {
        return null;
    }

    /**
     * @return list<Tag>
     */
    public function findAll(Scope $scope): array
    {
        $rows = $this->findAllScoped($scope, Criteria::new()->orderBy('name'), $this->qualify('name') . ' ASC');

        return array_map($this->hydrate(...), $rows);
    }

    public function find(Scope $scope, int $id): ?Tag
    {
        $row = $this->findOneScoped($scope, Criteria::new()->equals('id', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Resolve a list of tag names to ids, creating any that do not yet exist.
     *
     * @param list<string> $names
     * @return list<int>
     */
    public function resolveOrCreate(Scope $scope, array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $existing = $this->findOneScoped($scope, Criteria::new()->search(['name'], $name));
            if ($existing !== null && mb_strtolower((string) $existing['name']) === mb_strtolower($name)) {
                $ids[] = (int) $existing['id'];
                continue;
            }

            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $ids[] = $this->insertScoped($scope, [
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return array_values(array_unique($ids));
    }

    public function create(Scope $scope, string $name): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->insertScoped($scope, [
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function rename(Scope $scope, int $id, string $name): void
    {
        $this->updateScoped($scope, $id, [
            'name' => $name,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->deleteScoped($scope, $id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Tag
    {
        return new Tag(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            name: (string) $row['name'],
        );
    }
}
