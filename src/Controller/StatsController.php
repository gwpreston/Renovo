<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\CatchUpService;
use App\Service\InstanceSettingsService;
use App\Service\StatsService;
use App\Service\SubscriptionService;
use App\Service\UsageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The statistics page: cost per period, year-over-year, and the "worth it?"
 * ranking.
 */
final class StatsController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly StatsService $stats,
        private readonly UsageService $usage,
        private readonly SubscriptionService $subscriptions,
        private readonly CatchUpService $catchUp,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $this->catchUp->run($scope);

        $all = $this->subscriptions->allForStats($scope);

        $recurring = [];
        foreach ($all as $subscription) {
            $yearly = $subscription->isActive ? $subscription->yearlyMinor() : null;
            if ($yearly !== null) {
                $currency = $subscription->price->currency;
                $recurring[$currency] = ($recurring[$currency] ?? 0) + $yearly;
            }
        }

        $combined = $this->stats->combine($recurring);

        return $this->render($request, $response, 'stats/index.twig', [
            'per_period' => $this->stats->perPeriod($combined['amount_minor']),
            'combined_yearly' => $combined,
            'by_currency' => $recurring,
            'year_over_year' => $this->stats->yearOverYear($scope),
            'value_signals' => $this->usage->valueSignals($all),
            'base_currency' => $this->settings->baseCurrency(),
            'max_rating' => UsageService::MAX_RATING,
        ]);
    }
}
