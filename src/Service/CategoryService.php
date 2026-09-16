<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Category;
use App\Repository\CategoryRepository;
use App\Security\Scope;

final class CategoryService
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    /**
     * @return list<Category>
     */
    public function all(Scope $scope): array
    {
        return $this->categories->findAll($scope);
    }

    /**
     * @throws ValidationException
     */
    public function create(Scope $scope, string $name, ?string $colour): int
    {
        $name = trim($name);
        $this->assertValid($scope, $name, $colour);

        return $this->categories->create($scope, $name, $this->normaliseColour($colour));
    }

    /**
     * @throws ValidationException
     */
    public function rename(Scope $scope, int $id, string $name, ?string $colour): void
    {
        $name = trim($name);
        $this->assertValid($scope, $name, $colour, $id);

        $this->categories->update($scope, $id, $name, $this->normaliseColour($colour));
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->categories->delete($scope, $id);
    }

    /**
     * @throws ValidationException
     */
    private function assertValid(Scope $scope, string $name, ?string $colour, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::field('name', 'error.category.name_required');
        }
        if (mb_strlen($name) > 60) {
            throw ValidationException::field('name', 'error.name.too_long_60');
        }

        $existing = $this->categories->findByName($scope, $name);
        $isDuplicate = $existing !== null
            && $existing->id !== $ignoreId
            && mb_strtolower($existing->name) === mb_strtolower($name);

        if ($isDuplicate) {
            throw ValidationException::field('name', 'error.category.duplicate');
        }

        if ($colour !== null && $colour !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $colour) !== 1) {
            throw ValidationException::field('colour', 'error.category.colour_required');
        }
    }

    private function normaliseColour(?string $colour): ?string
    {
        return $colour === null || $colour === '' ? null : strtolower($colour);
    }
}
