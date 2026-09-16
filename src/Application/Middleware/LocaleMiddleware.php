<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\I18n\LocaleContext;
use App\I18n\Locales;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Decides what language a request is answered in.
 *
 * This runs globally, so it is the only thing that can set a locale for a
 * request with nobody signed in: the sign-in page, the password-reset form and
 * the first-run wizard all get the best match for the browser's
 * `Accept-Language`, falling back to the instance's configured locale.
 *
 * For a signed-in user, AuthenticationMiddleware overwrites this with their
 * stored preference once the account has been loaded. Two writers, in that
 * order, because the authenticated one cannot run globally — the user is not
 * known until routing has chosen a route and its middleware has run — and a
 * stated preference must win over a browser header.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LocaleContext $context,
        private readonly Locales $locales,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context->set($this->locales->negotiate($request->getHeaderLine('Accept-Language')));

        $response = $handler->handle($request);

        // Set from the context rather than from what was negotiated above: by
        // the time the response exists, an authenticated request may have
        // moved to the user's own locale, and the header should say which
        // language the body is actually in.
        return $response->withHeader('Content-Language', $this->context->tag());
    }
}
