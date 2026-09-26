<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Persistence\PdoSessionHandler;
use App\Security\CsrfTokenManager;
use App\Security\RequestContext;
use App\Security\RequestContextHolder;
use App\Security\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Installs the database-backed session handler and starts the session.
 *
 * Outermost of the application middleware, because everything below it —
 * CSRF, authentication, flash messages — needs a live session.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly PdoSessionHandler $handler,
        private readonly RequestContextHolder $requestContext,
        private readonly CsrfTokenManager $csrf,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Captured before anything else runs: the audit log and the session
        // list both need it, and by the time a service asks, the request object
        // is several layers away.
        $context = RequestContext::fromRequest($request);
        $this->requestContext->set($context);

        if (!$this->session->isStarted()) {
            $this->handler->describeClient($context->ipAddress, $context->userAgent);
            session_set_save_handler($this->handler, true);
            $this->session->start();
        }

        // Minted here rather than lazily by the first template that asks for
        // it. The error middleware renders *after* this one has written and
        // closed the session, so a token first created while rendering an error
        // page would be embedded in that page and never stored — and the form
        // it decorated would then be rejected. Doing it up front means the
        // token a page carries is always one the session holds.
        $this->csrf->token();

        try {
            return $handler->handle($request);
        } finally {
            // In a finally, not after the handler, because the error middleware
            // sits *outside* this one: an HTTP exception — a 403 from a
            // permission check, a 404 from a missing row — unwinds straight
            // past here. Without this, PHP's shutdown handler would write the
            // session row with no user id attached, quietly detaching a live
            // session from its account and hiding it from the security page.
            //
            // The user id is read here rather than earlier so that the login
            // request, which sets it mid-flight, writes the correct value.
            if ($this->session->isStarted()) {
                $userId = $this->session->get(AuthenticationMiddleware::SESSION_USER_ID);
                $this->handler->associateUser(is_int($userId) ? $userId : null);
                $this->handler->setPersistent($this->session->isPersistent());

                // Write and close before the response goes out so the row is
                // not held open across a slow client.
                session_write_close();
            }
        }
    }
}
