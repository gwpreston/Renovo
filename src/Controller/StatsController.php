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
 * The analytics screen: the spend-insight card, the KPI row, the twelve months
 * behind, the spending trajectory, the category donut, year over year and the
 * notable subscriptions — and, below them, the cost-per-period figures and the
 * "worth it?" ranking this page has always carried.
 *
 * Thin, like every controller here. `AnalyticsScreenService` assembles the
 * screen, insights and usage ranking included, so this hands it a scope and
 * hands the template what comes back.
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

        // Runs the catch-up before it reads anything, so the figures below are
        // computed from current prices.
        $overview = $this->analytics->overview($scope);

        /** @var array<string, mixed> $kpis */
        $kpis = $overview['kpis'];
        /** @var array{currency: string, amount_minor: int|null, unconvertible: list<string>} $combinedYearly */
        $combinedYearly = $kpis['yearly']['combined'];

        return $this->render($request, $response, 'stats/index.twig', [
            // Rule-based, and absent when no rule fired: the card states a
            // figure only when it can name the subscriptions behind it.
            'insights' => $overview['insights'],
            'kpis' => $kpis,
            'trajectory' => $overview['trajectory'],
            // The same chart drawn over the window that has already happened.
            // Reconstructed rather than recorded, which the card says on its
            // own behalf rather than leaving the reader to assume a ledger.
            'history' => $overview['history'],
            'categories' => $overview['categories'],
            'year_over_year' => $overview['year_over_year'],
            'notable' => $overview['notable'],
            // The per-period figures derive from the annual one rather than
            // from each other, so a weekly number always multiplies up to its
            // own yearly one.
            'per_period' => $this->stats->perPeriod($combinedYearly['amount_minor']),
            'combined_yearly' => $combinedYearly,
            'yearly_by_currency' => $kpis['yearly']['totals'],
            // The same ranking the insight rules read, computed once by the
            // assembler above rather than a second time here.
            'value_signals' => $overview['value_signals'],
            'base_currency' => $this->settings->baseCurrency(),
            'max_rating' => UsageService::MAX_RATING,
        ]);
    }
}
