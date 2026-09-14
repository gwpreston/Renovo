<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Native PHP sessions with hardened cookie settings.
 *
 * The storage handler is injected separately (see PdoSessionHandler) so that
 * sessions live in the database and survive container restarts and multiple
 * app replicas without needing a shared filesystem.
 *
 * @phpstan-type SessionSettings array{name: string, lifetime: int, secure: bool,
 *     samesite: string, path: string, domain: string}
 */
final class Session implements SessionInterface
{
    private const FLASH_KEY = '_flashes';

    /**
     * @param SessionSettings $settings
     */
    public function __construct(private readonly array $settings)
    {
    }

    public function start(): void
    {
        if ($this->isStarted()) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf('Cannot start session: output already sent at %s:%d.', $file, $line));
        }

        session_name($this->settings['name']);
        session_set_cookie_params([
            'lifetime' => $this->settings['lifetime'],
            'path' => $this->settings['path'],
            'domain' => $this->settings['domain'],
            'secure' => $this->settings['secure'],
            'httponly' => true,
            'samesite' => $this->settings['samesite'],
        ]);

        session_start([
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'cookie_httponly' => 1,
            'sid_length' => 48,
            'sid_bits_per_character' => 5,
        ]);
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function regenerate(): void
    {
        if ($this->isStarted()) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (!$this->isStarted()) {
            return;
        }

        $_SESSION = [];
        session_destroy();
    }

    public function id(): string
    {
        return session_id() ?: '';
    }

    public function flash(string $type, string $message): void
    {
        /** @var list<array{type: string, message: string}> $flashes */
        $flashes = $this->get(self::FLASH_KEY, []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->set(self::FLASH_KEY, $flashes);
    }

    public function consumeFlashes(): array
    {
        /** @var list<array{type: string, message: string}> $flashes */
        $flashes = $this->get(self::FLASH_KEY, []);
        $this->remove(self::FLASH_KEY);

        return $flashes;
    }
}
