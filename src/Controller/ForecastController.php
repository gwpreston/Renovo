<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\CatchUpService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The twelve-month forecast.
 *
 * A "just mine" toggle rather than two pages: the household view and the
 * per-member view answer the same question at different scopes, and the
 * service already takes the member as a parameter.
 */
final class ForecastController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly ForecastService $forecast,
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

        $months = $this->forecast->monthly(
            $scope,
            ForecastService::DEFAULT_MONTHS,
            $mineOnly ? $scope->userId : null,
        );

        return $this->render($request, $response, 'forecast/index.twig', [
            'months' => $months,
            'peak_minor' => $this->peakOf($months),
            'mine_only' => $mineOnly,
            'base_currency' => $this->settings->baseCurrency(),
        ]);
    }

    /**
     * The busiest month, so each month's bar can be drawn relative to
     * something.
     *
     * Worked out here rather than in the template, for two reasons: templates
     * hold no logic, and Twig scopes `{% set %}` to the loop body it appears
     * in — accumulating a maximum across a `for` would silently leave the
     * variable at its initial value outside the loop, with no error.
     *
     * @param list<array<string, mixed>> $months
     */
    private function peakOf(array $months): int
    {
        $peak = 0;

        foreach ($months as $month) {
            $combined = $month['combined_minor'] ?? null;
            if (is_int($combined) && $combined > $peak) {
                $peak = $combined;
            }
        }

        return $peak;
    }
}
