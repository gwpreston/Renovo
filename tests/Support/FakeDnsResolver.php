<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\DnsResolver;

/**
 * DNS with the answers written down in advance.
 *
 * The SSRF tests are about what happens when a name resolves somewhere it
 * should not, and a test that needed a real domain pointing at 127.0.0.1 to
 * prove it would depend on somebody's registration staying paid up. Here the
 * hostile answer is simply declared.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $answers;

    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param array<string, list<string>> $answers Host => addresses.
     */
    public function __construct(array $answers = [])
    {
        $this->answers = $answers;
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->answers[strtolower($host)] ?? [];
    }
}
