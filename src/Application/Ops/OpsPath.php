<?php

declare(strict_types=1);

namespace App\Application\Ops;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The paths an orchestrator and a monitoring system talk to.
 *
 * Named in one place because more than one middleware has to agree about
 * them: the setup guard must let them through on an instance that has never
 * been configured — a health check that redirects to the first-run wizard is a
 * container that never becomes ready — and demo mode has nothing to say about
 * them either.
 */
final class OpsPath
{
    public const LIVENESS = '/healthz';
    public const READINESS = '/readyz';
    public const METRICS = '/metrics';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::LIVENESS, self::READINESS, self::METRICS];
    }

    public static function matches(ServerRequestInterface $request): bool
    {
        return in_array($request->getUri()->getPath(), self::all(), true);
    }
}
