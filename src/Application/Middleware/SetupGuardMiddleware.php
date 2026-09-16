<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Ops\OpsPath;
use App\Service\SetupService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends an un-configured instance to the first-run wizard, and a configured
 * one away from it.
 *
 * The second direction matters as much as the first: leaving /setup reachable
 * after the instance is live would let anybody create a second instance
 * administrator.
 *
 * The health and metrics endpoints are exempt. A container orchestrator polls
 * them before anybody has opened a browser, and a liveness probe that answered
 * "302 to /setup" would report a healthy instance as broken — or, worse, be
 * followed, and count a redirect as a pass.
 *
 * `/setup/notifications` is the exception, and deliberately so. It is the
 * wizard's second step, it runs *after* the administrator account exists, and
 * it sits inside the authenticated group — it creates no account and grants no
 * access, so the reason the rest of /setup is closed does not apply to it.
 */
final class SetupGuardMiddleware implements MiddlewareInterface
{
    public const POST_SETUP_STEP = '/setup/notifications';

    public function __construct(
        private readonly SetupService $setup,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        // The whole subtree, not just the page: the step has a form that posts
        // to /setup/notifications/channels and a finish button that posts to
        // /setup/notifications/finish, and a guard that let the page through
        // but bounced its own forms would leave a step that cannot be
        // completed.
        $isPostSetupStep = $path === self::POST_SETUP_STEP
            || str_starts_with($path, self::POST_SETUP_STEP . '/');
        $isSetupRoute = str_starts_with($path, '/setup') && !$isPostSetupStep;
        $isAsset = str_starts_with($path, '/assets');

        if ($isAsset || OpsPath::matches($request)) {
            return $handler->handle($request);
        }

        if ($this->setup->isRequired() && !$isSetupRoute) {
            return $this->redirect('/setup');
        }

        if (!$this->setup->isRequired() && $isSetupRoute) {
            return $this->redirect('/');
        }

        return $handler->handle($request);
    }

    private function redirect(string $location): ResponseInterface
    {
        return $this->responseFactory->createResponse(302)->withHeader('Location', $location);
    }
}
