<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\ScopeViolationException;
use App\Security\SessionInterface;
use App\Service\CategoryService;
use App\Service\TagService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class CategoryController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        return $this->render($request, $response, 'categories/index.twig', [
            'categories' => $this->categories->all($scope),
            'tags' => $this->tags->all($scope),
            'errors' => [],
            'values' => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        try {
            $this->categories->create(
                $scope,
                is_scalar($body['name'] ?? null) ? (string) $body['name'] : '',
                is_scalar($body['colour'] ?? null) ? (string) $body['colour'] : null,
            );
        } catch (ValidationException $exception) {
            return $this->render($request, $response->withStatus(422), 'categories/index.twig', [
                'categories' => $this->categories->all($scope),
                'tags' => $this->tags->all($scope),
                'errors' => $exception->errors(),
                'values' => $body,
            ]);
        }

        $this->flash('success', 'flash.category_added');

        return $this->redirectAfterWrite($request, $response, '/categories');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        try {
            $this->categories->rename(
                $scope,
                (int) $id,
                is_scalar($body['name'] ?? null) ? (string) $body['name'] : '',
                is_scalar($body['colour'] ?? null) ? (string) $body['colour'] : null,
            );
        } catch (ValidationException $exception) {
            return $this->render($request, $response->withStatus(422), 'categories/index.twig', [
                'categories' => $this->categories->all($scope),
                'tags' => $this->tags->all($scope),
                'errors' => $exception->errors(),
                'values' => $body,
            ]);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.category_renamed');

        return $this->redirectAfterWrite($request, $response, '/categories');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $this->categories->delete($this->scope($request), (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.category_deleted');

        return $this->redirectAfterWrite($request, $response, '/categories');
    }

    public function deleteTag(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        try {
            $this->tags->delete($this->scope($request), (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.tag_deleted');

        return $this->redirectAfterWrite($request, $response, '/categories');
    }
}
