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
 * The analytics screen: the KPI row, the spending trajectory, the category
 * donut, year over year and the notable subscriptions — and, below them, the
 * cost-per-period figures and the "worth it?" ranking this page has always
 * carried.
 *
 * Thin, like every controller here. `AnalyticsScreenService` assembles the
 * screen and the usage ranking is `UsageService`'s; this hands one the scope
 * and the other the rows the first already loaded.
 */
final class StatsController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly AnalyticsScreenService $analytics,
        private readonly StatsService $stats,
        private readonly UsageService $usage,
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
            'kpis' => $kpis,
            'trajectory' => $overview['trajectory'],
            'categories' => $overview['categories'],
            'year_over_year' => $overview['year_over_year'],
            'notable' => $overview['notable'],
            // The per-period figures derive from the annual one rather than
            // from each other, so a weekly number always multiplies up to its
            // own yearly one.
            'per_period' => $this->stats->perPeriod($combinedYearly['amount_minor']),
            'combined_yearly' => $combinedYearly,
            'yearly_by_currency' => $kpis['yearly']['totals'],
            // The same rows the KPIs were counted from: the ranking walks the
            // household's subscriptions, and it is not walking them twice.
            'value_signals' => $this->usage->valueSignals($overview['subscriptions']),
            'base_currency' => $this->settings->baseCurrency(),
            'max_rating' => UsageService::MAX_RATING,
        ]);
    }
}
