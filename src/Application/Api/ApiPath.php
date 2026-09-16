<?php

declare(strict_types=1);

namespace App\Application\Api;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Where the API lives, in one place.
 *
 * Three unrelated pieces of plumbing need to agree on this: the CSRF middleware
 * (which skips it), the error handler (which renders JSON for it) and the route
 * file (which mounts it). Three separate string literals would agree right up
 * until the version prefix changed.
 */
final class ApiPath
{
    public const VERSION = 'v1';
    public const PREFIX = '/api/' . self::VERSION;

    public static function matches(ServerRequestInterface $request): bool
    {
        return self::matchesPath($request->getUri()->getPath());
    }

    public static function matchesPath(string $path): bool
    {
        return $path === self::PREFIX || str_starts_with($path, self::PREFIX . '/');
    }

    /**
     * Whether the request offers a bearer token.
     *
     * Asked by the CSRF middleware, which runs long before anything has tried
     * to *verify* that token. The question here is only "is this request being
     * made the way an API client makes one", not "is the credential good" —
     * that is the API middleware's job, and it rejects with 401 either way.
     */
    public static function hasBearerCredential(ServerRequestInterface $request): bool
    {
        return stripos($request->getHeaderLine('Authorization'), 'bearer ') === 0;
    }
}
