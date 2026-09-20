<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\GuardedClient;
use App\Http\ResolvedTarget;
use App\Http\UrlGuard;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * A guarded client that runs the **real** `UrlGuard` and then stops short of
 * the socket.
 *
 * `FakeGuardedClient` answers the question "what did the notifier put on the
 * wire?" and deliberately vets nothing. This answers a different question that
 * a fake cannot: *would the guard have allowed this at all?* — which is what
 * matters for the self-hostable channels, where a Mattermost or ntfy server on
 * a LAN must actually be reachable once an administrator trusts it, and must
 * not be reachable before that.
 *
 * The seam is the guard, not the transport, because the guard is what makes the
 * decision. `HttpClient` is final and would dial a real address, so it is left
 * out; everything up to and including `UrlGuard::inspect()` is the production
 * code path.
 */
final class GuardingFakeClient implements GuardedClient
{
    public ?ResolvedTarget $lastTarget = null;

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly int $status = 200,
        private readonly string $body = '',
    ) {
    }

    public function send(RequestInterface $request, bool $httpsOnly = false): ResponseInterface
    {
        $this->requests[] = $request;

        // The real decision. Throws BlockedTargetException exactly as it would
        // in production, which the notifier must turn into a channel failure.
        $this->lastTarget = $this->guard->inspect($request->getUri(), $httpsOnly);

        return (new ResponseFactory())
            ->createResponse($this->status)
            ->withBody((new StreamFactory())->createStream($this->body));
    }
}
