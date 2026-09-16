<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\SavedView;
use App\Domain\SubscriptionFilter;
use App\Repository\SavedViewRepository;
use App\Security\Scope;

/**
 * Naming a set of list filters so it can be reached again.
 *
 * The value stored is the query string the list itself produced, run back
 * through `SubscriptionFilter` before it is saved. That round trip is the
 * validation: whatever the browser sent, what lands in the database is a
 * filter this application would have generated, with its sort key, direction
 * and currency already checked against the allow-lists the list view uses.
 *
 * No permission is named anywhere in here. A saved view belongs to one
 * account, changes what that account sees and nothing else, and a Viewer may
 * keep one for the same reason they may choose a theme.
 */
final class SavedViewService
{
    /** Enough for anybody; a guard against a script filling the table. */
    public const MAX_PER_USER = 30;

    public function __construct(private readonly SavedViewRepository $repository)
    {
    }

    /**
     * @return list<SavedView>
     */
    public function forScope(Scope $scope): array
    {
        return $this->repository->findForUser($scope->userId, $scope->householdId);
    }

    /**
     * @param array<string, mixed> $queryParams The list's current query string,
     *        already parsed by the request.
     * @throws ValidationException
     */
    public function save(Scope $scope, string $name, array $queryParams): int
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::field('name', 'error.saved_view.name_required');
        }

        if (mb_strlen($name) > 60) {
            throw ValidationException::field('name', 'error.name.too_long_60');
        }

        if ($this->repository->countForUser($scope->userId) >= self::MAX_PER_USER) {
            throw ValidationException::field('name', 'error.saved_view.too_many', ['max' => self::MAX_PER_USER]);
        }

        // Round-tripped, not stored as it arrived: the filter object is the
        // allow-list, so anything it does not recognise is gone by the time
        // this is written.
        $query = SubscriptionFilter::fromQueryParams($queryParams)->toQueryString(['page' => null]);

        return $this->repository->create($scope->userId, $scope->householdId, $name, $query);
    }

    public function delete(Scope $scope, int $id): bool
    {
        return $this->repository->delete($id, $scope->userId);
    }
}
