<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\BlockedTargetException;
use App\Http\GuardedClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Stands in for the guarded client so a notifier's own behaviour can be tested
 * without a network — what it posts, which headers it sets, and how it reads
 * the answer.
 *
 * It records every request, which is how the tests assert that a Gotify token
 * went in a header rather than a query string, and that Slack was asked over
 * https only.
 */
final class FakeGuardedClient implements GuardedClient
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<bool> */
    public array $httpsOnlyFlags = [];

    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly ?BlockedTargetException $failure,
    ) {
    }

    public static function returning(string $body = '', int $status = 200): self
    {
        return new self($status, $body, null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function returningJson(array $payload, int $status = 200): self
    {
        return new self($status, json_encode($payload, JSON_THROW_ON_ERROR), null);
    }

    public static function blocking(string $reason = 'private address'): self
    {
        return new self(0, '', BlockedTargetException::malformed($reason));
    }

    public function send(RequestInterface $request, bool $httpsOnly = false): ResponseInterface
    {
        $this->requests[] = $request;
        $this->httpsOnlyFlags[] = $httpsOnly;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return (new ResponseFactory())
            ->createResponse($this->status)
            ->withBody((new StreamFactory())->createStream($this->body));
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    public function lastBody(): string
    {
        $request = $this->lastRequest();

        return $request === null ? '' : (string) $request->getBody();
    }
}
