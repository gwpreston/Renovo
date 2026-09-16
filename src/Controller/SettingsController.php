<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Domain\Currency;
use App\Domain\Density;
use App\Domain\IsolationMode;
use App\Domain\LandingView;
use App\Domain\Role;
use App\Domain\Theme;
use App\Domain\WeekStart;
use App\I18n\Locales;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Security\SessionInterface;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRateService;
use App\Service\HouseholdSettingsService;
use App\Service\InstanceAdminService;
use App\Service\InstanceSettingsService;
use App\Service\DashboardLayoutService;
use App\Service\TrustedHostService;
use App\Service\UserPreferencesService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Personal, household and instance settings.
 *
 * The three tiers are separate routes with separate permissions: changing a
 * theme needs nothing, renaming a household needs Owner/Admin, and the
 * isolation mode needs instance administration.
 */
final class SettingsController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly InstanceSettingsService $settings,
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateProviderRegistry $rateProviders,
        private readonly TrustedHostService $trustedHosts,
        private readonly HouseholdSettingsService $householdSettings,
        private readonly InstanceAdminService $instanceAdmin,
        private readonly UserPreferencesService $preferences,
        private readonly DashboardLayoutService $dashboard,
        private readonly Locales $locales,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        return $this->render($request, $response, 'settings/index.twig', [
            // Only an instance administrator can act on this list, and the
            // template hides it from everybody else — but the list is also the
            // instance's network exceptions, so it is not handed to a page that
            // has no business rendering it either.
            'trusted_hosts' => $this->user($request)->isInstanceAdmin ? $this->trustedHosts->all() : [],
            'household' => $scope->hasHousehold() ? $this->households->findById((int) $scope->householdId) : null,
            'members' => $scope->hasHousehold()
                ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
                : [],
            'roles' => Role::assignable(),
            'themes' => Theme::cases(),
            'densities' => Density::cases(),
            'week_starts' => WeekStart::cases(),
            'landing_views' => LandingView::cases(),
            'locale_choices' => $this->locales->choices(),
            'dashboard_layout' => $this->dashboard->forUser($this->user($request)->id),
            'currencies' => Currency::common(),
            'isolation_modes' => IsolationMode::cases(),
            'rate_providers' => $this->rateProviders->all(),
            'rates' => [
                'provider' => $this->rates->provider()->key(),
                'provider_label' => $this->rates->provider()->label(),
                'last_refreshed_at' => $this->rates->lastRefreshedAt(),
                'is_stale' => $this->rates->isStale(),
                'is_misconfigured' => $this->rates->isMisconfigured(),
                'currency_count' => count($this->rates->availableCurrencies()),
            ],
            'instance' => [
                'base_currency' => $this->settings->baseCurrency(),
                'isolation_mode' => $this->settings->isolationMode()->value,
                'allow_registration' => $this->settings->registrationAllowed(),
                'demo_mode' => $this->settings->isDemoMode(),
            ],
        ]);
    }

    /**
     * The theme switch, which appears on every page and therefore submits on
     * its own rather than as part of the settings form.
     */
    public function updateTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $theme = is_scalar($body['theme'] ?? null) ? (string) $body['theme'] : null;

        $this->preferences->updateTheme($this->user($request)->id, $theme);

        // Back where they were: the switch is in the navigation bar, so
        // sending them to the settings page from an arbitrary page would be a
        // navigation they did not ask for.
        $referer = $request->getHeaderLine('Referer');
        $target = $referer !== '' ? $this->samePathAsUs($request, $referer) : '/settings';

        return $this->redirectAfterWrite($request, $response, $target);
    }

    public function updatePreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $userId = $this->user($request)->id;

        $this->preferences->update($userId, $body);

        $this->dashboard->update(
            $userId,
            is_array($body['card_position'] ?? null) ? $body['card_position'] : [],
            is_array($body['card_visible'] ?? null) ? $body['card_visible'] : [],
        );

        $this->flash('success', 'flash.preferences_saved');

        return $this->redirectAfterWrite($request, $response, '/settings');
    }

    /**
     * A Referer, reduced to a path on this instance.
     *
     * Never used as a redirect target as it arrived: an absolute URL in that
     * header is attacker-controllable, and handing it to a Location header is
     * an open redirect. Only the path survives, and only when the host matches.
     */
    private function samePathAsUs(ServerRequestInterface $request, string $referer): string
    {
        $parts = parse_url($referer);
        if ($parts === false) {
            return '/settings';
        }

        $host = $parts['host'] ?? null;
        if ($host !== null && $host !== $request->getUri()->getHost()) {
            return '/settings';
        }

        $path = $parts['path'] ?? '/';

        return str_starts_with($path, '/') ? $path : '/settings';
    }

    public function updateHousehold(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        if ($scope->hasHousehold()) {
            $user = $this->user($request);
            $householdId = (int) $scope->householdId;
            $roles = $body['roles'] ?? null;

            $this->householdSettings->rename(
                $user,
                $householdId,
                is_scalar($body['name'] ?? null) ? (string) $body['name'] : '',
            );

            $this->householdSettings->changeRoles($user, $householdId, is_array($roles) ? $roles : []);
        }

        $this->flash('success', 'flash.household_saved');

        return $this->redirectAfterWrite($request, $response, '/settings');
    }

    public function updateInstance(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $this->instanceAdmin->apply($this->user($request), [
            'base_currency' => is_scalar($body['base_currency'] ?? null) ? (string) $body['base_currency'] : '',
            'isolation_mode' => is_scalar($body['isolation_mode'] ?? null) ? (string) $body['isolation_mode'] : '',
            'allow_registration' => ($body['allow_registration'] ?? '0') === '1',
            'rate_provider' => is_scalar($body['rate_provider'] ?? null) ? (string) $body['rate_provider'] : '',
            'rate_provider_key' => is_scalar($body['rate_provider_key'] ?? null)
                ? (string) $body['rate_provider_key']
                : '',
            'clear_rate_provider_key' => ($body['clear_rate_provider_key'] ?? '') === '1',
            'demo_mode' => ($body['demo_mode'] ?? '0') === '1',
        ]);

        $this->flash('success', 'flash.instance_saved');

        return $this->redirectAfterWrite($request, $response, '/settings');
    }

    /**
     * Add a destination the SSRF guard will permit despite it being private.
     *
     * Guarded by instance administration at the route, which is the level this
     * belongs at: the answer to "may this server connect to 192.168.1.0/24" is
     * a fact about the network the instance sits on, not a preference of
     * whoever happens to be configuring a webhook.
     */
    public function addTrustedHost(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->trustedHosts->add(
                is_scalar($body['pattern'] ?? null) ? (string) $body['pattern'] : '',
                is_scalar($body['note'] ?? null) ? (string) $body['note'] : null,
                $this->user($request),
            );
            $this->flash('success', 'flash.trusted_host_added');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, '/settings');
    }

    public function deleteTrustedHost(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->trustedHosts->remove((int) $id, $this->user($request));
        $this->flash('success', 'flash.trusted_host_removed');

        return $this->redirectAfterWrite($request, $response, '/settings');
    }
}
