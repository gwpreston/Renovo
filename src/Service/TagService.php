<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Tag;
use App\Repository\TagRepository;
use App\Security\Scope;

final class TagService
{
    public function __construct(private readonly TagRepository $tags)
    {
    }

    /**
     * @return list<Tag>
     */
    public function all(Scope $scope): array
    {
        return $this->tags->findAll($scope);
    }

    /**
     * A tag with nothing on it yet. Tags are usually made by typing one on a
     * subscription; this is the Settings page's way of making one first.
     *
     * @throws ValidationException
     */
    public function create(Scope $scope, string $name): int
    {
        $name = trim($name);
        $this->assertValid($scope, $name);

        return $this->tags->create($scope, $name);
    }

    /**
     * Renaming a tag renames it on every subscription that carries it, since
     * they carry the tag and not its name.
     *
     * @throws ValidationException
     * @throws \App\Security\ScopeViolationException when the tag is not this household's.
     */
    public function rename(Scope $scope, int $id, string $name): void
    {
        $name = trim($name);
        $this->assertValid($scope, $name, $id);

        $this->tags->rename($scope, $id, $name);
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->tags->delete($scope, $id);
    }

    /**
     * @throws ValidationException
     */
    private function assertValid(Scope $scope, string $name, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::field('name', 'error.tag.required');
        }

        // The column's width. Tags typed on a subscription are held to it too.
        if (mb_strlen($name) > 50) {
            throw ValidationException::field('name', 'error.tag.too_long');
        }

        // Compared whole and without regard to case, as the tag field on a
        // subscription resolves names: "tv" and "TV" are one tag.
        foreach ($this->tags->findAll($scope) as $tag) {
            if ($tag->id !== $ignoreId && mb_strtolower($tag->name) === mb_strtolower($name)) {
                throw ValidationException::field('name', 'error.tag.duplicate');
            }
        }
    }
}
