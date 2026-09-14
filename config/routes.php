<?php

/**
 * Routes.
 *
 * Note the shape of the authenticated group: every mutating route names the
 * permission it needs, and that permission is checked by middleware before the
 * controller runs. A route added without one would still be behind
 * authentication, but the convention here is that a write always declares what
 * it requires — a Viewer reaching any of these gets a 403.
 */

declare(strict_types=1);

use App\Application\Middleware\AuthenticationMiddleware;
use App\Application\Middleware\RequirePermissionMiddleware;
use App\Controller\Auth\LoginController;
use App\Controller\Auth\PasswordResetController;
use App\Controller\Auth\RegisterController;
use App\Controller\Auth\VerifyEmailController;
use App\Controller\CategoryController;
use App\Controller\DashboardController;
use App\Controller\SettingsController;
use App\Controller\SetupController;
use App\Controller\SubscriptionController;
use App\Domain\Permission;
// Imported by name: `App\Security\...` would resolve against the `Slim\App`
// import below rather than the application's root namespace.
use App\Security\PermissionService;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $container = $app->getContainer();
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('The application has no container.');
    }

    $requires = static fn (Permission $permission): RequirePermissionMiddleware
        => new RequirePermissionMiddleware($container->get(PermissionService::class), $permission);

    // ----------------------------------------------------------------------
    // First-run wizard (closed once the instance has an administrator)
    // ----------------------------------------------------------------------
    $app->get('/setup', [SetupController::class, 'showForm'])->setName('setup');
    $app->post('/setup', [SetupController::class, 'submit']);

    // ----------------------------------------------------------------------
    // Public authentication routes
    // ----------------------------------------------------------------------
    $app->get('/login', [LoginController::class, 'showForm'])->setName('login');
    $app->post('/login', [LoginController::class, 'submit']);
    $app->post('/logout', [LoginController::class, 'logout'])->setName('logout');

    $app->get('/register', [RegisterController::class, 'showForm'])->setName('register');
    $app->post('/register', [RegisterController::class, 'submit']);

    $app->get('/verify-email', [VerifyEmailController::class, 'verify'])->setName('verify-email');

    $app->get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->setName('forgot-password');
    $app->post('/forgot-password', [PasswordResetController::class, 'submitRequest']);
    $app->get('/reset-password', [PasswordResetController::class, 'showResetForm'])->setName('reset-password');
    $app->post('/reset-password', [PasswordResetController::class, 'submitReset']);

    // ----------------------------------------------------------------------
    // Everything below requires a signed-in user
    // ----------------------------------------------------------------------
    // Not a static closure: Slim binds the group callable to the container,
    // and a static closure cannot be bound.
    $app->group('', function (RouteCollectorProxy $group) use ($requires): void {
        $group->get('/', [DashboardController::class, 'index'])->setName('dashboard');

        $group->get('/subscriptions', [SubscriptionController::class, 'index'])
            ->setName('subscriptions')
            ->add($requires(Permission::ViewSubscriptions));

        $group->get('/subscriptions/new', [SubscriptionController::class, 'createForm'])
            ->setName('subscription-new')
            ->add($requires(Permission::CreateSubscription));

        $group->post('/subscriptions', [SubscriptionController::class, 'create'])
            ->add($requires(Permission::CreateSubscription));

        $group->get('/subscriptions/{id:[0-9]+}/edit', [SubscriptionController::class, 'editForm'])
            ->setName('subscription-edit')
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}', [SubscriptionController::class, 'update'])
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/toggle', [SubscriptionController::class, 'toggle'])
            ->add($requires(Permission::UpdateSubscription));

        $group->post('/subscriptions/{id:[0-9]+}/delete', [SubscriptionController::class, 'delete'])
            ->add($requires(Permission::DeleteSubscription));

        $group->get('/categories', [CategoryController::class, 'index'])
            ->setName('categories')
            ->add($requires(Permission::ViewSubscriptions));

        $group->post('/categories', [CategoryController::class, 'create'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/categories/{id:[0-9]+}', [CategoryController::class, 'update'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/categories/{id:[0-9]+}/delete', [CategoryController::class, 'delete'])
            ->add($requires(Permission::ManageCategories));

        $group->post('/tags/{id:[0-9]+}/delete', [CategoryController::class, 'deleteTag'])
            ->add($requires(Permission::ManageTags));

        $group->get('/settings', [SettingsController::class, 'index'])->setName('settings');

        // Changing your own theme needs no permission beyond being signed in.
        $group->post('/settings/theme', [SettingsController::class, 'updateTheme']);

        $group->post('/settings/household', [SettingsController::class, 'updateHousehold'])
            ->add($requires(Permission::ManageHousehold));

        $group->post('/settings/instance', [SettingsController::class, 'updateInstance'])
            ->add($requires(Permission::ManageInstance));
    })->add(AuthenticationMiddleware::class);
};
