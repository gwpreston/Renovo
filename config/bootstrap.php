<?php

/**
 * Builds the application. Shared by the web front controller, the CLI and the
 * functional tests, so that a console command and a test run against exactly
 * the same container the web app does.
 *
 * @param array<string, mixed> $overrides Container definitions to replace.
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Handlers\Strategies\RequestResponseNamedArgs;

return static function (bool $withMiddleware = true, array $overrides = []): App {
    $root = dirname(__DIR__);

    // .env is optional: in Docker and in CI the variables are already in the
    // environment, and a missing file there is normal rather than an error.
    if (is_file($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }

    /** @var array<string, mixed> $settings */
    $settings = require $root . '/config/settings.php';

    $builder = new ContainerBuilder();
    if (!$settings['app']['debug'] && $overrides === []) {
        $builder->enableCompilation($settings['paths']['cache'] . '/container');
    }

    (require $root . '/config/container.php')($builder, $settings);

    // Tests replace a small number of services (the session, the clock) so the
    // rest of the container — the real routes, middleware, repositories and
    // scoping layer — is exercised exactly as it is in production.
    if ($overrides !== []) {
        $builder->addDefinitions($overrides);
    }

    $container = $builder->build();

    AppFactory::setContainer($container);
    $app = AppFactory::create();

    // Route placeholders are passed to controllers as individual named
    // arguments, so `/subscriptions/{id}` reaches a method declaring
    // `string $id`. Slim's default strategy hands over the whole arguments
    // array instead, which makes every such signature a TypeError at runtime.
    //
    // This must be set before any route is registered: the collector copies
    // the strategy onto each route as it is created.
    $app->getRouteCollector()->setDefaultInvocationStrategy(new RequestResponseNamedArgs());

    (require $root . '/config/routes.php')($app);

    if ($withMiddleware) {
        (require $root . '/config/middleware.php')($app, $settings);
    }

    return $app;
};
