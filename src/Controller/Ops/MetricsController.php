<?php

declare(strict_types=1);

namespace App\Controller\Ops;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Repository\UserRepository;
use App\Security\SessionInterface;
use App\Service\MetricsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * The Prometheus endpoint.
 *
 * Unlike the health checks, this one is closed. The numbers on it — how many
 * accounts, how many subscriptions, how many notifications failed — are not
 * secrets on their own, but they describe an instance's contents and its
 * activity, and a self-hosted application is often the only thing on a domain.
 *
 * Two ways in, and no third:
 *
 *  - a bearer token, set as `METRICS_TOKEN`. Prometheus can send one, and an
 *    unset variable means the endpoint does not exist at all rather than
 *    existing unprotected. That ordering — off unless configured — is the same
 *    one every other secret in this application follows.
 *  - a signed-in instance administrator, so an operator can read the same
 *    numbers in a browser while working out why the dashboard is empty.
 *
 * A wrong token gets 404, not 401. There is nothing to be gained by confirming
 * that the endpoint exists to somebody who cannot read it.
 */
final class MetricsController
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly SessionInterface $session,
        private readonly UserRepository $users,
        private readonly string $token,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->mayRead($request)) {
            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write($this->metrics->render());

        return $response
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function mayRead(ServerRequestInterface $request): bool
    {
        // The session is read here rather than through the authentication
        // middleware: this route is mounted outside the authenticated group,
        // because a monitoring system has no session to be redirected with.
        $userId = $this->session->get(AuthenticationMiddleware::SESSION_USER_ID);
        if (is_int($userId) && $this->users->findById($userId)?->isInstanceAdmin === true) {
            return true;
        }

        if ($this->token === '') {
            return false;
        }

        $header = $request->getHeaderLine('Authorization');
        if (stripos($header, 'bearer ') !== 0) {
            return false;
        }

        // Constant-time: the token is a shared secret, and a comparison that
        // returns early is a comparison that can be measured.
        return hash_equals($this->token, trim(substr($header, 7)));
    }
}
