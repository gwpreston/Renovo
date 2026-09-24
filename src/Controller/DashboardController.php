<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\DashboardCard;
use App\Domain\DashboardView;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\DashboardLayoutService;
use App\Service\DashboardService;
use App\Service\HouseholdDashboardService;
use App\Service\UserPreferencesService;
use App\Support\Clock;
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
        private readonly HouseholdDashboardService $household,
        private readonly DashboardLayoutService $layout,
        private readonly UserPreferencesService $preferences,
        private readonly Clock $clock,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $user = $this->user($request);

        // A chip on the subscriptions table asks for the table, and nothing
        // else: recomputing the whole view would re-run the catch-up and walk
        // the forecast to answer a question about eight rows. Keyed on the
        // chip's own parameter as well as the htmx header, so no other htmx
        // request to `/` can be mistaken for it.
        $show = $request->getQueryParams()['show'] ?? null;
        if ($this->isHtmx($request) && is_string($show)) {
            return $this->render($request, $response, 'dashboard/_recent_table.twig', [
                'table' => $this->dashboard->table($scope, $show),
            ]);
        }

        // No "Open on" redirect here. That preference is about where a *session*
        // begins, so it is applied once, when the browser signs in — see
        // SignInService::landingFor(). Applied on this route it behaved as a
        // permanent redirect, and since the navigation's Dashboard item points
        // at `/`, it made the dashboard unreachable for anyone whose preference
        // was some other screen.
        $view = $user->dashboardViewPreference();
        $cards = $this->layout->visibleFor($user->id, $view);

        $data = [];
        if ($scope->hasHousehold()) {
            $data = $view === DashboardView::Household
                ? $this->household->household($scope)
                : $this->dashboard->overview($scope);

            // The table is opt-in, so its query runs only for somebody who
            // has turned it on.
            if (in_array(DashboardCard::Recent, $cards, true)) {
                $data['table'] = $this->dashboard->table($scope, is_string($show) ? $show : 'active');
            }
        }

        return $this->render($request, $response, 'dashboard/index.twig', $data + [
            'dashboard_view' => $view,
            'dashboard_views' => DashboardView::cases(),
            'dashboard_cards' => $cards,
            'first_name' => $user->firstName(),
            'today' => $this->clock->today(),
        ]);
    }

    /**
     * The Overview / Household toggle.
     *
     * Remembered per account, so the dashboard opens on the same view next
     * time. A personal preference like the theme: it changes nothing anybody
     * else sees, so it asks for no permission beyond being signed in.
     */
    public function updateView(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $this->preferences->updateDashboardView(
            $this->user($request)->id,
            is_scalar($body['view'] ?? null) ? (string) $body['view'] : null,
        );

        return $this->redirectAfterWrite($request, $response, '/');
    }
}
