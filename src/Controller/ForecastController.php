<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\CatchUpService;
use App\Service\ForecastScreenService;
use App\Service\InstanceSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The twelve-month forecast.
 *
 * A "just mine" toggle rather than two pages: the household view and the
 * per-member view answer the same question at different scopes, and the
 * service already takes the member as a parameter. Everything on the page is
 * `ForecastScreenService`'s.
 */
final class ForecastController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly ForecastScreenService $screen,
        private readonly CatchUpService $catchUp,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $this->catchUp->run($scope);

        $mineOnly = ($request->getQueryParams()['mine'] ?? '') === '1';

        return $this->render($request, $response, 'forecast/index.twig', $this->screen->overview(
            $scope,
            $mineOnly ? $scope->userId : null,
        ) + [
            'mine_only' => $mineOnly,
            'base_currency' => $this->settings->baseCurrency(),
        ]);
    }
}
