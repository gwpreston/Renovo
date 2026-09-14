<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The list view's filter, sort and paging state, parsed once from the query
 * string and passed around as a value object.
 *
 * Sort keys are symbolic; the repository maps them to real columns through its
 * own allow-list, so an arbitrary `?sort=` value can never reach the SQL.
 */
final class SubscriptionFilter
{
    public const SORTS = ['name', 'next_payment', 'price', 'created'];

    public const DEFAULT_PER_PAGE = 25;

    /**
     * @param list<int> $tagIds
     */
    public function __construct(
        public readonly string $search = '',
        public readonly ?int $categoryId = null,
        public readonly array $tagIds = [],
        public readonly ?int $ownerUserId = null,
        public readonly ?string $currency = null,
        public readonly ?SubscriptionType $type = null,
        public readonly bool $includeInactive = false,
        public readonly string $sort = 'next_payment',
        public readonly string $direction = 'asc',
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): self
    {
        $tagIds = [];
        $rawTags = $query['tag'] ?? null;
        foreach (is_array($rawTags) ? $rawTags : (is_scalar($rawTags) ? [$rawTags] : []) as $tag) {
            $id = (int) $tag;
            if ($id > 0) {
                $tagIds[] = $id;
            }
        }

        $sort = is_string($query['sort'] ?? null) ? $query['sort'] : 'next_payment';
        if (!in_array($sort, self::SORTS, true)) {
            $sort = 'next_payment';
        }

        $currency = is_string($query['currency'] ?? null) && $query['currency'] !== ''
            ? Currency::normalise($query['currency'])
            : null;

        return new self(
            search: is_string($query['q'] ?? null) ? trim($query['q']) : '',
            categoryId: self::positiveIntOrNull($query['category'] ?? null),
            tagIds: $tagIds,
            ownerUserId: self::positiveIntOrNull($query['owner'] ?? null),
            currency: $currency !== null && Currency::isValidCode($currency) ? $currency : null,
            type: SubscriptionType::tryFrom(is_string($query['type'] ?? null) ? $query['type'] : ''),
            includeInactive: ($query['inactive'] ?? '') === '1',
            sort: $sort,
            direction: ($query['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            page: max(1, (int) ($query['page'] ?? 1)),
            perPage: self::DEFAULT_PER_PAGE,
        );
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->categoryId !== null
            || $this->tagIds !== []
            || $this->ownerUserId !== null
            || $this->currency !== null
            || $this->type !== null
            || $this->includeInactive;
    }

    /**
     * Rebuild the query string, overriding some parameters. Used by the sort
     * headers and pager links so they keep the rest of the filter intact.
     *
     * @param array<string, string|int|null> $overrides
     */
    public function toQueryString(array $overrides = []): string
    {
        $params = array_filter([
            'q' => $this->search !== '' ? $this->search : null,
            'category' => $this->categoryId,
            'owner' => $this->ownerUserId,
            'currency' => $this->currency,
            'type' => $this->type?->value,
            'inactive' => $this->includeInactive ? '1' : null,
            'sort' => $this->sort,
            'dir' => $this->direction,
            'page' => $this->page > 1 ? $this->page : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($params[$key]);
                continue;
            }
            $params[$key] = $value;
        }

        $query = http_build_query($params);
        foreach ($this->tagIds as $tagId) {
            $query .= ($query === '' ? '' : '&') . 'tag[]=' . $tagId;
        }

        return $query;
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
