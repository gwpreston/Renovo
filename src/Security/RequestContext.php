<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a request came from: the two facts an audit entry needs and that no
 * service should have to reach into the HTTP layer to find out.
 */
final class RequestContext
{
    private const UNKNOWN_IP = '0.0.0.0';

    private function __construct(
        public readonly string $ipAddress,
        public readonly ?string $userAgent,
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $server = $request->getServerParams();
        $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : self::UNKNOWN_IP;

        $agent = $request->getHeaderLine('User-Agent');

        return new self($ip, $agent === '' ? null : mb_substr($agent, 0, 255));
    }

    /**
     * The scheduler and console commands act with nobody behind them. They
     * still write audit entries, so they still need a context; this one says
     * plainly that there was no browser involved rather than inventing an
     * address.
     */
    public static function system(): self
    {
        return new self(self::UNKNOWN_IP, 'console');
    }

    public static function of(string $ipAddress, ?string $userAgent = null): self
    {
        return new self($ipAddress, $userAgent);
    }
}
