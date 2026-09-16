<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Ops\OpsPath;
use App\Repository\HttpMetricsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Counts requests by status class, for `/metrics`.
 *
 * Added to the stack only when metrics are switched on, so an instance that
 * does not scrape pays nothing — not a row, not a write, not a microsecond.
 *
 * By status class rather than by path: a counter per URL would grow without
 * limit the moment somebody scanned the instance for admin panels, and the
 * question these numbers answer — "is it serving, and how fast?" — does not
 * need the path.
 *
 * The ops endpoints do not count themselves. A scrape every fifteen seconds
 * would otherwise be most of the traffic the numbers describe.
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly HttpMetricsRepository $metrics)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (OpsPath::matches($request)) {
            return $handler->handle($request);
        }

        $startedAt = hrtime(true);

        try {
            $response = $handler->handle($request);
        } finally {
            $elapsed = (int) ((hrtime(true) - $startedAt) / 1_000);

            // Recorded in the finally block, so a request that threw is still
            // counted — as a 500, which is what the client saw.
            $status = isset($response) ? $response->getStatusCode() : 500;
            $this->metrics->record(self::bucketFor($status), $elapsed);
        }

        return $response;
    }

    public static function bucketFor(int $status): string
    {
        return match (true) {
            $status >= 500 => '5xx',
            $status >= 400 => '4xx',
            $status >= 300 => '3xx',
            $status >= 200 => '2xx',
            default => 'other',
        };
    }
}
