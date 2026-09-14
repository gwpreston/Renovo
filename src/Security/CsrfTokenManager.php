<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Per-session CSRF token.
 *
 * One token per session rather than one per form: it is simpler, survives the
 * back button and multiple tabs, and htmx requests can carry it in a header
 * set once on the page body. The token is compared with hash_equals.
 */
final class CsrfTokenManager
{
    public const FIELD_NAME = '_csrf';
    public const HEADER_NAME = 'X-CSRF-Token';

    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function isValid(?string $candidate): bool
    {
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }

        $token = $this->session->get(self::SESSION_KEY);

        return is_string($token) && $token !== '' && hash_equals($token, $candidate);
    }

    /**
     * Called on login and logout: a new session must not inherit the old
     * session's token.
     */
    public function rotate(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
