<?php

/**
 * The middleware stack, outermost first.
 *
 * Slim runs the last-added middleware first, so this file reads in reverse of
 * execution order; the comments give the real order.
 */

declare(strict_types=1);

use App\Application\Handler\HttpErrorHandler;
use App\Application\Middleware\CsrfMiddleware;
use App\Application\Middleware\InstanceContextMiddleware;
use App\Application\Middleware\SessionMiddleware;
use App\Application\Middleware\SetupGuardMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Views\Twig;

return static function (App $app, array $settings): void {
    $container = $app->getContainer();
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('The application has no container.');
    }

    // 6. Routing, then the route's own middleware and controller.
    $app->addRoutingMiddleware();

    // 5. CSRF, on every unsafe method. Added after body parsing below, which
    //    means it *runs* after it: the token may arrive in the parsed body, and
    //    for anything but a form encoding that body does not exist until the
    //    parser has run.
    $app->add(CsrfMiddleware::class);

    // 4. Body parsing, so controllers and the CSRF check see an array.
    $app->addBodyParsingMiddleware();

    // 3. Instance-wide view context.
    $app->add(InstanceContextMiddleware::class);

    // 2. Redirect to or away from the first-run wizard.
    $app->add(SetupGuardMiddleware::class);

    // 1. Session: everything above needs it.
    $app->add(SessionMiddleware::class);

    // 0. Errors, outermost so that it catches everything below.
    $errorMiddleware = $app->addErrorMiddleware(
        (bool) $settings['app']['debug'],
        true,
        true,
        $container->get(LoggerInterface::class),
    );

    $errorMiddleware->setDefaultErrorHandler(new HttpErrorHandler(
        $app->getCallableResolver(),
        $app->getResponseFactory(),
        $container->get(Twig::class),
        (bool) $settings['app']['debug'],
        $container->get(LoggerInterface::class),
    ));
};
