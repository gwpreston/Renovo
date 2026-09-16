<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Api\Resource;
use App\Domain\Entity\Category;
use App\Domain\Entity\Tag;
use App\Service\CategoryService;
use App\Service\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Categories and tags.
 *
 * They travel together because a subscription refers to both and a client that
 * cannot resolve a `category_id` or invent a tag has only half an API. Tags have
 * no create endpoint on purpose: they are created by naming them on a
 * subscription, exactly as in the web form, and a separate endpoint would give
 * two ways to make one and two chances to get the deduplication wrong.
 */
final class TaxonomyApiController extends ApiController
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly TagService $tags,
    ) {
    }

    public function categories(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'data' => array_map(
                static fn (Category $category): array => Resource::category($category),
                $this->categories->all($this->scope($request)),
            ),
        ]);
    }

    public function createCategory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->payload($request);

        $id = $this->categories->create(
            $scope,
            $this->string($body, 'name'),
            $this->nullableString($body, 'colour'),
        );

        return $this->json($response, ['data' => $this->find($request, $id)], 201);
    }

    public function updateCategory(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $body = $this->payload($request);

        $this->categories->rename(
            $scope,
            (int) $id,
            $this->string($body, 'name'),
            $this->nullableString($body, 'colour'),
        );

        return $this->json($response, ['data' => $this->find($request, (int) $id)]);
    }

    public function deleteCategory(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->categories->delete($this->scope($request), (int) $id);

        return $this->noContent($response);
    }

    public function tags(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'data' => array_map(
                static fn (Tag $tag): array => Resource::tag($tag),
                $this->tags->all($this->scope($request)),
            ),
        ]);
    }

    public function deleteTag(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->tags->delete($this->scope($request), (int) $id);

        return $this->noContent($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(ServerRequestInterface $request, int $id): array
    {
        foreach ($this->categories->all($this->scope($request)) as $category) {
            if ($category->id === $id) {
                return Resource::category($category);
            }
        }

        throw new HttpNotFoundException($request, 'No such category.');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function nullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        if ($value === null || !is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
