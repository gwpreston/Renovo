<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Domain\Entity\ApiToken;
use App\Domain\Entity\User;
use App\Security\ScopeFactory;
use App\Service\ApiTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Authenticates a request from an API token, and from nothing else.
 *
 * The absence is the security property. This middleware never reads the
 * session, so no route behind it can be driven by a browser's ambient cookie —
 * which is what makes it safe for CsrfMiddleware to skip `/api/v1`. Take the
 * cookie fallback away and you need CSRF; add one back and you have silently
 * made every API mutation forgeable from any page the user visits. There is a
 * test that a valid session with no token gets 401.
 *
 * The scope is built by the ordinary ScopeFactory from the token holder's
 * membership. An API request is therefore isolated exactly as the same user's
 * browser session would be — there is no second set of visibility rules to keep
 * in step, which is the same reason controllers and endpoints share services.
 *
 * Subclasses differ in one thing only: where the credential is read from. That
 * is a class each rather than a flag because the calendar feed accepts a token
 * in the URL, and a URL-borne credential ends up in proxy logs and browser
 * history. Which routes tolerate that is decided by which middleware they are
 * given, not by a condition inside a shared one that a later route could be
 * added on the wrong side of.
 */
abstract class TokenAuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE_TOKEN = 'api_token';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ScopeFactory $scopeFactory,
    ) {
    }

    /**
     * The presented credential, or null when none was offered.
     */
    abstract protected function credential(ServerRequestInterface $request): ?string;

    /**
     * Whether a token presented this way may be used to write.
     */
    abstract protected function acceptsWrites(): bool;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $presented = $this->credential($request);
        if ($presented === null || $presented === '') {
            throw new HttpUnauthorizedException($request, 'This endpoint requires an API token.');
        }

        $resolved = $this->tokens->authenticate($presented);
        if ($resolved === null) {
            // Expired, revoked, malformed and never-existed are one answer.
            throw new HttpUnauthorizedException($request, 'That API token is not valid.');
        }

        [$token, $user] = $resolved;

        $this->assertMayProceed($request, $token);

        $scope = $this->scopeFactory->forUser($user, $token->householdId);

        return $handler->handle(
            $request
                ->withAttribute(AuthenticationMiddleware::ATTRIBUTE_USER, $user)
                ->withAttribute(AuthenticationMiddleware::ATTRIBUTE_SCOPE, $scope)
                ->withAttribute(self::ATTRIBUTE_TOKEN, $token),
        );
    }

    /**
     * A read-only token is refused a mutating request here, before any route
     * runs. This narrows; it never widens — the role's own permission check
     * still happens afterwards, so a write token held by a Viewer writes
     * nothing.
     */
    private function assertMayProceed(ServerRequestInterface $request, ApiToken $token): void
    {
        $isSafe = in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true);

        if (!$isSafe && (!$this->acceptsWrites() || !$token->abilities->allowsWrites())) {
            throw new HttpForbiddenException($request, 'This token is read-only.');
        }

        if (!$this->acceptsWrites() && $token->abilities->allowsWrites()) {
            // A write-capable token offered where credentials travel in the URL.
            // Refused rather than downgraded: a token that can change data does
            // not belong in a calendar subscription URL, and quietly accepting
            // it teaches the operator that it is fine to put one there.
            throw new HttpForbiddenException($request, 'This endpoint accepts read-only tokens only.');
        }
    }
}
