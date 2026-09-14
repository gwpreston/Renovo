<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class HttpClientException extends RuntimeException implements ClientExceptionInterface
{
    public static function transport(RequestInterface $request, string $reason): self
    {
        return new self(sprintf(
            'Request to %s failed: %s',
            (string) $request->getUri()->withUserInfo(''),
            $reason,
        ));
    }

    public static function responseTooLarge(RequestInterface $request, int $limit): self
    {
        return new self(sprintf(
            'Response from %s exceeded the %d byte limit.',
            (string) $request->getUri()->withUserInfo(''),
            $limit,
        ));
    }

    public static function unsupportedScheme(string $scheme): self
    {
        return new self(sprintf('Refusing to fetch scheme "%s"; only http and https are allowed.', $scheme));
    }
}
