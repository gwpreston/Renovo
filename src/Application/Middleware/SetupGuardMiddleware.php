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
 * The wizard's later steps are the exception, and deliberately so. The
 * household and reminders steps and the page setup ends on run *after* the
 * administrator account exists, and they sit inside the authenticated group —
 * they create no account and grant no access, so the reason the rest of /setup
 * is closed does not apply to them. They are named one by one: `/setup` as a
 * prefix is exactly the door this middleware exists to shut.
 */
final class SetupGuardMiddleware implements MiddlewareInterface
{
    /**
     * The signed-in steps, each with everything beneath it.
     *
     * @var list<string>
     */
    public const POST_SETUP_STEPS = ['/setup/household', '/setup/notifications', '/setup/done'];

    public function __construct(
        private readonly SetupService $setup,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        // The whole subtree, not just the page: the reminders step has forms
        // that post to /setup/notifications/channels and .../finish, and a
        // guard that let the page through but bounced its own forms would
        // leave a step that cannot be completed.
        $isPostSetupStep = false;
        foreach (self::POST_SETUP_STEPS as $step) {
            if ($path === $step || str_starts_with($path, $step . '/')) {
                $isPostSetupStep = true;
            }
        }
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
