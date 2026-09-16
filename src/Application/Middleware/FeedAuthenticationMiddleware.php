<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Authentication for the calendar feed, where the credential travels in the URL.
 *
 * Calendar clients cannot send an Authorization header — you hand them a URL and
 * they fetch it on a timer for ever. So this one reads `?token=`, and pays for
 * that in the only currency available: it accepts read-only tokens exclusively,
 * and it is applied to exactly one route.
 *
 * Both halves are enforced by the base class. Neither is a condition some future
 * route could be added on the wrong side of, because the way a route opts in is
 * by being given this middleware rather than the header one.
 */
final class FeedAuthenticationMiddleware extends TokenAuthenticationMiddleware
{
    public const QUERY_PARAMETER = 'token';

    protected function credential(ServerRequestInterface $request): ?string
    {
        $query = $request->getQueryParams();
        $value = $query[self::QUERY_PARAMETER] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    protected function acceptsWrites(): bool
    {
        return false;
    }
}
