<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A destination the guard has cleared, and the exact address it cleared.
 *
 * The address is the point of this object. Validating a host name and then
 * handing the name to curl would leave a window in which the name resolves
 * again — to something else. Carrying the vetted address through to the
 * connection closes it: curl is told to use this address for this host, so the
 * address that was checked is the address that is dialled.
 *
 * `$ipAddress` is null in exactly one case: an outbound proxy applies, so the
 * proxy resolves the name and there is nothing here to pin. That is the
 * operator's explicit choice of egress path, and the guard says so rather than
 * pretending to a guarantee it cannot make.
 */
final class ResolvedTarget
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $scheme,
        public readonly ?string $ipAddress,
        public readonly bool $isTrusted,
        public readonly bool $viaProxy = false,
    ) {
    }

    /**
     * The `host:port:address` triple curl wants, with an IPv6 literal bracketed
     * the way curl expects it.
     */
    public function curlResolveEntry(): ?string
    {
        if ($this->ipAddress === null) {
            return null;
        }

        $address = str_contains($this->ipAddress, ':') ? '[' . $this->ipAddress . ']' : $this->ipAddress;

        return sprintf('%s:%d:%s', $this->host, $this->port, $address);
    }
}
