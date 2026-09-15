<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The current request's origin, set once per request and readable by any
 * service that needs to write an audit entry.
 *
 * The alternative was threading an IP address and a user agent through every
 * service method that might one day be audited, which is how audit coverage
 * ends up depending on whether a signature was updated. This is a single
 * mutable object with one writer — the middleware — and it defaults to the
 * console context so that a command-line run is never missing one.
 */
final class RequestContextHolder
{
    private RequestContext $context;

    public function __construct(?RequestContext $context = null)
    {
        $this->context = $context ?? RequestContext::system();
    }

    public function set(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function get(): RequestContext
    {
        return $this->context;
    }
}
