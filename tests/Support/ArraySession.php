<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\SessionInterface;

/**
 * An in-memory session for functional tests.
 *
 * Native PHP sessions send cookies, which a test harness cannot do; swapping
 * only this one service keeps the rest of the stack — routes, middleware,
 * CSRF, the scoping layer — exactly as it runs in production.
 */
final class ArraySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    private bool $started = true;

    private string $id = 'test-session-id';

    public function start(): void
    {
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(8));
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->started = false;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Pin the id, for a test about which session survives something.
     *
     * The real session's id is decided by PHP; here it has to be decided by
     * the test, because "everything but this browser" is only a meaningful
     * assertion if the test knows which browser this one is.
     */
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function flash(string $type, string $key, array $parameters = []): void
    {
        $this->data['_flashes'][] = ['type' => $type, 'message' => $key, 'parameters' => $parameters];
    }

    public function consumeFlashes(): array
    {
        /** @var list<array{type: string, message: string, parameters: array<string, string|int|float>}> $flashes */
        $flashes = $this->data['_flashes'] ?? [];
        unset($this->data['_flashes']);

        return $flashes;
    }
}
