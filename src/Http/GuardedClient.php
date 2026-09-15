<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The contract for sending a request to a destination a user chose.
 *
 * Note what does *not* implement this: `HttpClient`. That is the whole reason
 * the interface is shaped this way rather than reusing PSR-18's
 * `ClientInterface`. If the guarded path were expressed as a `ClientInterface`,
 * a container definition — or a test, or a future refactor — could hand a
 * notifier the plain client, and nothing would look wrong: same type, same
 * method, no vetting. Here the plain client cannot satisfy the type at all, so
 * the substitution is a compile-time impossibility instead of a review
 * comment.
 *
 * It exists as an interface rather than a final class purely so that a test can
 * stand in for it. A fake used in a test is a fake by intent; a plain client
 * used in production is a vulnerability by accident, and only the second one
 * needs to be made impossible.
 */
interface GuardedClient
{
    /**
     * @param bool $httpsOnly Refuse plain http even to a trusted host.
     * @throws BlockedTargetException when the destination is not allowed.
     * @throws \Psr\Http\Client\ClientExceptionInterface on a transport failure.
     */
    public function send(RequestInterface $request, bool $httpsOnly = false): ResponseInterface;
}
