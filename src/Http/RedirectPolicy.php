<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * Where a guarded request is allowed to be redirected to.
 *
 * The rule is deliberately stricter than a browser's: **a guarded request
 * follows redirects only within the same host.** The reason is not the
 * destination itself — the guard would vet a new host perfectly well — it is
 * what the request is carrying. A Gotify application token, a Slack bot token
 * or a webhook secret sits in a header, and a redirect is somebody else's
 * server asking us to send that header somewhere new. "Somewhere new" is never
 * a good enough reason.
 *
 * A downgrade from https to http is refused for the same reason: the hop that
 * leaks the token in plaintext is exactly the hop an attacker would engineer.
 * An upgrade the other way is fine.
 *
 * This class resolves the Location header and applies the rules; it touches no
 * network and holds no state, which is what makes each rule directly testable.
 */
final class RedirectPolicy
{
    /** Statuses that mean "go and ask over there instead". */
    public const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(private readonly UriFactoryInterface $uris)
    {
    }

    /**
     * @throws BlockedTargetException when the redirect may not be followed.
     */
    public function next(UriInterface $current, string $location): UriInterface
    {
        $location = trim($location);
        if ($location === '') {
            throw BlockedTargetException::redirect('the Location header was empty');
        }

        $target = $this->resolve($current, $location);

        $scheme = strtolower($target->getScheme());
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw BlockedTargetException::redirect(sprintf('"%s" is not a scheme we follow', $scheme));
        }

        if (strtolower($current->getScheme()) === 'https' && $scheme === 'http') {
            throw BlockedTargetException::redirect('it downgrades an encrypted connection to plain http');
        }

        if ($target->getUserInfo() !== '') {
            throw BlockedTargetException::redirect('it contains credentials');
        }

        if ($this->originOf($target) !== $this->originOf($current)) {
            throw BlockedTargetException::redirect('it points at a different host, which would send the '
                . 'credentials for this one somewhere else');
        }

        return $target;
    }

    /**
     * Whether a status code is one this policy handles at all.
     */
    public static function isRedirect(int $status): bool
    {
        return in_array($status, self::REDIRECT_STATUSES, true);
    }

    /**
     * A redirect that changes the method.
     *
     * 303 always becomes a GET; 301 and 302 do so in practice for anything
     * other than GET or HEAD, which is what every client and server on the web
     * has assumed for twenty years. 307 and 308 exist precisely to preserve the
     * method, and do.
     */
    public static function becomesGet(int $status, string $method): bool
    {
        $method = strtoupper($method);

        if ($status === 303) {
            return $method !== 'GET' && $method !== 'HEAD';
        }

        return in_array($status, [301, 302], true) && $method !== 'GET' && $method !== 'HEAD';
    }

    private function originOf(UriInterface $uri): string
    {
        $scheme = strtolower($uri->getScheme());
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        return strtolower(IpAddress::unbracket($uri->getHost())) . ':' . $port;
    }

    /**
     * Resolve a Location value against the URL it came from, covering the four
     * shapes a server may send: absolute, scheme-relative, root-relative and
     * path-relative.
     */
    private function resolve(UriInterface $current, string $location): UriInterface
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            return $this->uris->createUri($location);
        }

        if (str_starts_with($location, '//')) {
            return $this->uris->createUri($current->getScheme() . ':' . $location);
        }

        $authority = $current->getScheme() . '://' . $current->getAuthority();

        if (str_starts_with($location, '/')) {
            return $this->uris->createUri($authority . $location);
        }

        $base = $current->getPath();
        $directory = substr($base, 0, (int) strrpos($base, '/') + 1);
        if ($directory === '') {
            $directory = '/';
        }

        return $this->uris->createUri($authority . $directory . $location);
    }
}
