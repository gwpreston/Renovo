<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * API authentication: `Authorization: Bearer <token>`, and nothing else.
 *
 * No query-parameter fallback. A credential in a URL is recorded by every proxy
 * and browser between the client and here, and an endpoint that accepts one
 * "for convenience" is the endpoint people use.
 */
final class ApiAuthenticationMiddleware extends TokenAuthenticationMiddleware
{
    protected function credential(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '' || stripos($header, 'bearer ') !== 0) {
            return null;
        }

        $value = trim(substr($header, 7));

        return $value === '' ? null : $value;
    }

    protected function acceptsWrites(): bool
    {
        return true;
    }
}
