<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The real resolver, asking the system for A and AAAA records.
 *
 * Both families are asked for deliberately. A guard that only looked at IPv4
 * would pass a host whose AAAA record points at ::1, which is the same
 * vulnerability with a different address family.
 *
 * `dns_get_record()` talks to the configured name servers and does not consult
 * /etc/hosts, so a container resolving a Compose service name through the
 * resolver stub is covered, while a name that only exists in the hosts file is
 * not. `gethostbynamel()` does consult it, so it is used as a fallback for the
 * v4 case rather than as the primary source.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                foreach ($fallback as $address) {
                    if ($address !== '') {
                        $addresses[] = $address;
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
