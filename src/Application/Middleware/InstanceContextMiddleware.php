<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Service\InstanceSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * Publishes the instance-wide values every page shows — its name, its base
 * currency — as Twig globals.
 *
 * These depend on no user, so they are set once for every request, including
 * the sign-in page.
 */
final class InstanceContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Twig $view,
        private readonly InstanceSettingsService $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $environment = $this->view->getEnvironment();
        $environment->addGlobal('instance_name', $this->settings->instanceName());
        $environment->addGlobal('base_currency', $this->settings->baseCurrency());
        $environment->addGlobal('is_htmx', $request->getHeaderLine('HX-Request') === 'true');

        return $handler->handle($request);
    }
}
