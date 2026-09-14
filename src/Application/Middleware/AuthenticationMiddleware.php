<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Repository\UserRepository;
use App\Security\ScopeFactory;
use App\Security\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns the session's user id into a User and a Scope on the request.
 *
 * Every authenticated route runs through here, so a controller can rely on
 * both attributes existing and never has to build a Scope of its own — which
 * is the point: a hand-built Scope is a way to get the isolation rules wrong.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public const SESSION_USER_ID = 'user_id';
    public const SESSION_HOUSEHOLD_ID = 'household_id';

    public const ATTRIBUTE_USER = 'user';
    public const ATTRIBUTE_SCOPE = 'scope';

    public function __construct(
        private readonly SessionInterface $session,
        private readonly UserRepository $users,
        private readonly ScopeFactory $scopeFactory,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $this->session->get(self::SESSION_USER_ID);
        $user = is_int($userId) ? $this->users->findById($userId) : null;

        if ($user === null) {
            // The session may name a user who has since been deleted.
            $this->session->remove(self::SESSION_USER_ID);

            return $this->redirectToLogin($request);
        }

        $householdId = $this->session->get(self::SESSION_HOUSEHOLD_ID);
        $scope = $this->scopeFactory->forUser($user, is_int($householdId) ? $householdId : null);

        $request = $request
            ->withAttribute(self::ATTRIBUTE_USER, $user)
            ->withAttribute(self::ATTRIBUTE_SCOPE, $scope);

        return $handler->handle($request);
    }

    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        $target = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        $location = '/login';
        if ($target !== '/' && $request->getMethod() === 'GET') {
            $location .= '?next=' . rawurlencode($target);
        }

        // htmx swaps the response into the page, so a plain 302 would inject
        // the login form into a fragment. This tells it to navigate instead.
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return $this->responseFactory->createResponse(204)->withHeader('HX-Redirect', $location);
        }

        return $this->responseFactory->createResponse(302)->withHeader('Location', $location);
    }
}
