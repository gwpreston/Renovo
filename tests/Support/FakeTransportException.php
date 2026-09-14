<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * The PSR-18 marker for a transport failure, so FakeHttpClient can simulate a
 * provider being unreachable exactly as the real client reports it.
 */
final class FakeTransportException extends RuntimeException implements ClientExceptionInterface
{
}
