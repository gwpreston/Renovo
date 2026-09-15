<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A destination the guard refused.
 *
 * The messages are written to be shown to the person who typed the URL: they
 * say what was wrong and, where the allowlist is the answer, that an
 * administrator can permit it. They never include a resolved address, which
 * would turn a refusal into a way of reading the internal network one guess at
 * a time.
 */
final class BlockedTargetException extends RuntimeException implements ClientExceptionInterface
{
    public static function scheme(string $scheme): self
    {
        return new self(sprintf(
            'The URL uses "%s". Only https is allowed, or http for a host an administrator has trusted.',
            $scheme === '' ? 'no scheme' : $scheme,
        ));
    }

    public static function malformed(string $reason): self
    {
        return new self('That URL cannot be used: ' . $reason . '.');
    }

    public static function unresolvable(string $host): self
    {
        return new self(sprintf('The host "%s" does not resolve.', $host));
    }

    public static function privateAddress(string $host): self
    {
        return new self(sprintf(
            'The host "%s" resolves to a private or reserved address. An administrator can allow it '
            . 'by adding it to the trusted-host list.',
            $host,
        ));
    }

    public static function redirect(string $reason): self
    {
        return new self('The request was redirected somewhere it is not allowed to go: ' . $reason . '.');
    }

    public static function tooManyRedirects(int $limit): self
    {
        return new self(sprintf('The request was redirected more than %d times.', $limit));
    }
}
