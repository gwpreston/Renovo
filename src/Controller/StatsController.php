<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\AnalyticsScreenService;
use App\Service\InstanceSettingsService;
use App\Service\StatsService;
use App\Service\UsageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The analytics screen: the year-to-date KPI row, twelve months back and
 * twelve ahead, this year against last, who pays what, the most expensive
 * subscriptions, the household's price history and the insights — and, below
 * them, the breakdown donuts, the cost-per-period figures and the "worth it?"
 * ranking this page has always carried.
 *
 * Thin, like every controller here. `AnalyticsScreenService` assembles the
 * screen, so this hands it a scope and a page number and hands the template
 * what comes back.
 */
final class StatsController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly AnalyticsScreenService $analytics,
        private readonly StatsService $stats,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $page = $request->getQueryParams()['history_page'] ?? '1';

        // Runs the catch-up before it reads anything, so the figures below are
        // computed from current prices.
        $overview = $this->analytics->overview($scope, is_numeric($page) ? (int) $page : 1);

        /** @var array{currency: string, amount_minor: int|null, unconvertible: list<string>} $combinedYearly */
        $combinedYearly = $overview['yearly']['combined'];

        return $this->render($request, $response, 'stats/index.twig', $overview + [
            // The per-period figures derive from the annual one rather than
            // from each other, so a weekly number always multiplies up to its
            // own yearly one.
            'per_period' => $this->stats->perPeriod($combinedYearly['amount_minor']),
            'combined_yearly' => $combinedYearly,
            'yearly_by_currency' => $overview['yearly']['totals'],
            'base_currency' => $this->settings->baseCurrency(),
            'max_rating' => UsageService::MAX_RATING,
        ]);
    }
}
