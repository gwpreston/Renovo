<?php

declare(strict_types=1);

namespace App\Security;

interface SessionInterface
{
    public function start(): void;

    public function isStarted(): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    public function clear(): void;

    /**
     * Issue a new session id, keeping the data. Called on privilege changes
     * (login, password change) to prevent session fixation.
     */
    public function regenerate(): void;

    public function destroy(): void;

    public function id(): string;

    /**
     * Store a message for the next request only.
     *
     * What is stored is a translation key and its arguments, not a sentence.
     * A flash outlives the request that set it, and the request that renders
     * it may be in a different language — a message translated when it was
     * queued would be the wrong one by the time it was read.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function flash(string $type, string $key, array $parameters = []): void;

    /**
     * Read and clear all flash messages.
     *
     * @return list<array{type: string, message: string, parameters: array<string, string|int|float>}>
     */
    public function consumeFlashes(): array;
}
