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
 *  - the profile page itself, which is where the new password is typed — and
 *    only that path, not the routes beneath it: the page is a merge of the old
 *    account and preferences screens, so `/profile` as a prefix would hand
 *    back everything this middleware exists to withhold;
 *  - signing out, because being stuck on one screen is not a reason to be
 *    unable to leave;
 *  - the stylesheet and the rest of `/build`, which are outside this group
 *    anyway and are mentioned only so the next reader does not go looking.
 */
final class PasswordChangeRequiredMiddleware implements MiddlewareInterface
{
    private const TARGET = '/profile';

    /**
     * Paths that stay open exactly, and no path beneath them.
     *
     * `/profile` is here rather than in the prefix list, and the distinction
     * is the whole of this middleware's remaining strictness. The account
     * page and the preferences page were merged, so the page a locked-out
     * member is sent to is now `/profile` — and as a *prefix* that would open
     * every route under it: their preferences, their theme, their address,
     * their display name. None of those are why they are here. So the page
     * itself is reachable and the writes beneath it are named one at a time
     * below.
     *
     * @var list<string>
     */
    private const ALLOWED_EXACT = [
        self::TARGET,
    ];

    /**
     * Paths that stay open along with everything beneath them.
     *
     * The password form's own POST, the picture (an avatar upload is the one
     * other write this page offers that a member half-way through joining
     * plausibly wants, and it cannot be used to get anywhere), the avatar
     * endpoint that renders one, and the way out.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
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

        if (in_array($path, self::ALLOWED_EXACT, true)) {
            return $handler->handle($request);
        }

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
