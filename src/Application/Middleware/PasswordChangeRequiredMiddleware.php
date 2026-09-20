<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Domain\Entity\User;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What makes a temporary password temporary.
 *
 * A member provisioned without a mailbox is given a one-time password by the
 * Owner who set their account up. It is a real credential and it signs them
 * in, which is the point — but an account that stays on it indefinitely is an
 * account whose password somebody else has typed and may have written down.
 * So until they set one of their own, every page they ask for is the
 * change-password page.
 *
 * Applied as middleware and not as a check in each controller, because the
 * whole value of it is covering the routes nobody thought of. It runs inside
 * the authenticated group, so the user is already resolved.
 *
 * Three things are deliberately still reachable while the flag is set:
 *
 *  - the account page itself, which is where the new password is typed;
 *  - signing out, because being stuck on one screen is not a reason to be
 *    unable to leave;
 *  - the stylesheet and the rest of `/build`, which are outside this group
 *    anyway and are mentioned only so the next reader does not go looking.
 */
final class PasswordChangeRequiredMiddleware implements MiddlewareInterface
{
    private const TARGET = '/profile/account';

    /**
     * Paths that stay open while a password change is outstanding.
     *
     * Prefixes, so `/profile/account` covers the form and the POST that
     * answers it without listing both.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        self::TARGET,
        '/profile/password',
        '/profile/avatar',
        '/avatars',
        '/logout',
    ];

    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_USER);

        if (!$user instanceof User || !$user->mustChangePassword) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $handler->handle($request);
            }
        }

        // htmx swaps a response into the page, so a plain redirect would put
        // the account page inside a fragment. Same treatment as an expired
        // session gets in AuthenticationMiddleware, for the same reason.
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return $this->responseFactory->createResponse(204)->withHeader('HX-Redirect', self::TARGET);
        }

        return $this->responseFactory->createResponse(302)->withHeader('Location', self::TARGET);
    }
}
