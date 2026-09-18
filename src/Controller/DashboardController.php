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
        $view = $this->requestedView($request);

        // A chip on the table asks for the table, and nothing else. Rendering
        // the fragment on its own is not an optimisation here — recomputing
        // the overview would re-run the catch-up and walk the forecast to
        // answer a question about eight rows, and swapping the whole card
        // would take the chart's canvas with it.
        if ($this->isHtmx($request)) {
            return $this->render($request, $response, 'dashboard/_recent_table.twig', [
                'recent' => $this->dashboard->recent($scope, $view),
            ]);
        }

        $overview = $this->dashboard->overview($scope);

        return $this->render($request, $response, 'dashboard/index.twig', $overview + [
            // The near-window renewals the tiles were counted from, so the
            // badges below them are the same list rather than a second one.
            'recent' => $this->dashboard->recent($scope, $view, $overview['renewing_soon']),
            'base_currency' => $this->settings->baseCurrency(),
            'dashboard_cards' => $this->layout->visibleFor($user->id),
        ]);
    }

    private function requestedView(ServerRequestInterface $request): string
    {
        $view = $request->getQueryParams()['show'] ?? '';

        return is_string($view) ? $view : '';
    }
}
