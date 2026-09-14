<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Persistence\PdoSessionHandler;
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
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->session->isStarted()) {
            session_set_save_handler($this->handler, true);
            $this->session->start();
        }

        $response = $handler->handle($request);

        // Write and close before the response goes out so the row is not held
        // open across a slow client.
        if ($this->session->isStarted()) {
            session_write_close();
        }

        return $response;
    }
}
