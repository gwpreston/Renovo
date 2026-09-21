<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\DashboardLayoutService;
use App\Service\DashboardService;
use App\Service\InstanceSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class DashboardController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly DashboardService $dashboard,
        private readonly InstanceSettingsService $settings,
        private readonly DashboardLayoutService $layout,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $user = $this->user($request);

        // No "Open on" redirect here. That preference is about where a *session*
        // begins, so it is applied once, when the browser signs in — see
        // SignInService::landingFor(). Applied on this route it behaved as a
        // permanent redirect, and since the navigation's Dashboard item points
        // at `/`, it made the dashboard unreachable for anyone whose preference
        // was some other screen.
        $overview = $this->dashboard->overview($scope);

        return $this->render($request, $response, 'dashboard/index.twig', $overview + [
            'base_currency' => $this->settings->baseCurrency(),
            'dashboard_cards' => $this->layout->visibleFor($user->id),
        ]);
    }
}
