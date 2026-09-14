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

    public function delete(Scope $scope, int $id): void
    {
        $this->tags->delete($scope, $id);
    }
}
