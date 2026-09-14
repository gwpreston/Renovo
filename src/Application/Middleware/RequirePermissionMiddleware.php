<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Domain\Permission;
use App\Security\PermissionService;
use App\Security\Scope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;

/**
 * Server-side enforcement of one permission, attached to a route.
 *
 * A Viewer that reaches a mutating endpoint — by typing the URL, replaying a
 * form, or scripting it — gets a 403 here, before the controller runs. The
 * templates also hide the controls, but that is cosmetic; this is the check
 * that counts.
 */
final class RequirePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly Permission $permission,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_SCOPE);

        if (!$scope instanceof Scope) {
            // Reaching a permission check without a scope means the route is
            // wired up wrongly. Fail closed.
            throw new HttpForbiddenException($request, 'No scope is available for this request.');
        }

        $this->permissions->assert($request, $scope, $this->permission);

        return $handler->handle($request);
    }
}
