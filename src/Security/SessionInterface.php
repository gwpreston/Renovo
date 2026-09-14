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
     */
    public function flash(string $type, string $message): void;

    /**
     * Read and clear all flash messages.
     *
     * @return list<array{type: string, message: string}>
     */
    public function consumeFlashes(): array;
}
