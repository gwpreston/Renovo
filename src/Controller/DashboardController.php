<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Domain\LandingView;
use App\Domain\Permission;
use App\Security\PermissionService;
use App\Service\DashboardLayoutService;
use App\Service\InstanceSettingsService;
use App\Service\StatsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class DashboardController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly StatsService $stats,
        private readonly InstanceSettingsService $settings,
        private readonly DashboardLayoutService $layout,
        private readonly PermissionService $permissions,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $user = $this->user($request);

        // "Open on" is honoured here rather than by a redirect rule somewhere
        // in the middleware: this is the route that means "the beginning", and
        // a user who has chosen another page should land on it.
        $landing = $user->landingViewPreference();
        if (
            $landing !== LandingView::Dashboard
            && (!$landing->needsSubscriptionAccess()
                || $this->permissions->allows($scope, Permission::ViewSubscriptions))
        ) {
            return $this->redirect($response, $landing->path());
        }

        return $this->render($request, $response, 'dashboard/index.twig', [
            'stats' => $this->stats->dashboard($scope),
            'base_currency' => $this->settings->baseCurrency(),
            'dashboard_cards' => $this->layout->visibleFor($user->id),
        ]);
    }
}
