<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Ops\OpsPath;
use App\I18n\Translator;
use App\Repository\UserRepository;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;

/**
 * Makes the whole instance read-only while it is a demonstration.
 *
 * Global, and global is the point. A guard inside the authenticated group
 * would leave `/api/v1` writable, because the API authenticates by bearer
 * token and is mounted outside that group on purpose; a guard folded into
 * CsrfMiddleware would leave it writable for the same reason — the API is
 * exempt from CSRF precisely because it never reads a cookie. So this sits in
 * front of everything and judges the method, which is the one property every
 * route shares.
 *
 * Three things are still allowed, and each is allowed for a reason:
 *
 *  - **Signing in and out.** A demo where nobody can log in is a login page,
 *    and a session nobody can end is worse.
 *  - **The instance settings form, for an instance administrator.** Demo mode
 *    is switched on from a page that demo mode would otherwise switch off. An
 *    operator who turned it on by mistake would be left with a database edit
 *    as their only way out.
 *  - **Health and metrics.** They are reads, and they are not mounted behind
 *    anything that would have stopped them anyway.
 *
 * What it does *not* do is decide what anybody may see. Read isolation is the
 * scoping layer's job in demo mode exactly as it is the rest of the time — the
 * demo account is an ordinary account in its own household, and the seeded
 * data is the only data it has ever been able to see.
 */
final class DemoModeMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Sign-in and sign-out, which a demonstration still needs. */
    private const ALWAYS_ALLOWED = [
        '/login',
        '/login/two-factor',
        '/login/two-factor/cancel',
        '/login/two-factor/passkey/options',
        '/login/two-factor/passkey',
        '/login/passkey/options',
        '/login/passkey',
        '/logout',
    ];

    /** The way back out, for the only role that can have got in. */
    private const ADMIN_ALLOWED = ['/settings/instance'];

    public function __construct(
        private readonly InstanceSettingsService $settings,
        private readonly SessionInterface $session,
        private readonly UserRepository $users,
        private readonly Translator $translator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isAllowed($request)) {
            return $handler->handle($request);
        }

        throw new HttpForbiddenException($request, $this->translator->trans('error.demo.read_only'));
    }

    private function isAllowed(ServerRequestInterface $request): bool
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return true;
        }

        // Asked last of the cheap checks, and only for a write: the settings
        // are cached per request, but a demo instance is mostly reads and this
        // keeps them free.
        if (!$this->settings->isDemoMode()) {
            return true;
        }

        $path = $request->getUri()->getPath();

        if (in_array($path, self::ALWAYS_ALLOWED, true) || OpsPath::matches($request)) {
            return true;
        }

        return in_array($path, self::ADMIN_ALLOWED, true) && $this->isInstanceAdmin();
    }

    private function isInstanceAdmin(): bool
    {
        $userId = $this->session->get(AuthenticationMiddleware::SESSION_USER_ID);

        return is_int($userId) && $this->users->findById($userId)?->isInstanceAdmin === true;
    }
}
