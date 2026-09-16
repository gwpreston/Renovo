<?php

declare(strict_types=1);

namespace App\Controller\Ops;

use App\Service\HealthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Liveness and readiness, as an orchestrator expects them.
 *
 * Unauthenticated, because the caller is a container runtime with no session
 * and no token, and what is disclosed is a status word — not a count, not a
 * name, nothing that describes the data. `/metrics` is the endpoint with
 * numbers on it, and that one is not open.
 *
 * Neither answer is cacheable, and both say so: a cached "ready" is worse than
 * no answer at all.
 */
final class HealthController
{
    public function __construct(private readonly HealthService $health)
    {
    }

    /**
     * Alive: this process is answering requests. Nothing else is consulted —
     * see HealthService for why that is the point.
     */
    public function live(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['status' => 'ok'], 200);
    }

    public function ready(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $report = $this->health->readiness();

        return $this->json($response, $report, $report['status'] === 'ok' ? 200 : 503);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(ResponseInterface $response, array $body, int $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
