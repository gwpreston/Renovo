<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Turns a host name into the addresses it currently resolves to.
 *
 * This exists as an interface for one reason: the SSRF rules are the most
 * security-critical code in the application and they must be testable without
 * a network. A test that has to own a domain resolving to 127.0.0.1 in order
 * to prove that loopback is rejected is a test nobody runs.
 */
interface DnsResolver
{
    /**
     * Every address the host resolves to, v4 and v6.
     *
     * @return list<string> Normalised textual addresses; empty when the name
     *         does not resolve.
     */
    public function resolve(string $host): array;
}
