<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\SessionInterface;
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
        private readonly StatsService $stats,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        return $this->render($request, $response, 'dashboard/index.twig', [
            'stats' => $this->stats->dashboard($scope),
            'base_currency' => $this->settings->baseCurrency(),
        ]);
    }
}
