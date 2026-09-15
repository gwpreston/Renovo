<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Currency;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\SessionInterface;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRateService;
use App\Service\InstanceSettingsService;
use App\Service\TrustedHostService;
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
    private const THEMES = ['system', 'light', 'dark'];

    public function __construct(
        Twig $view,
        SessionInterface $session,
        private readonly InstanceSettingsService $settings,
        private readonly UserRepository $users,
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateProviderRegistry $rateProviders,
        private readonly TrustedHostService $trustedHosts,
    ) {
        parent::__construct($view, $session);
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
            'themes' => self::THEMES,
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
                'name' => $this->settings->instanceName(),
                'base_currency' => $this->settings->baseCurrency(),
                'isolation_mode' => $this->settings->isolationMode()->value,
                'allow_registration' => $this->settings->registrationAllowed(),
            ],
        ]);
    }

    public function updateTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $theme = is_scalar($body['theme'] ?? null) ? (string) $body['theme'] : 'system';

        if (!in_array($theme, self::THEMES, true)) {
            $theme = 'system';
        }

        $this->users->updateTheme($this->user($request)->id, $theme);

        return $this->redirectAfterWrite($request, $response, '/settings');
    }

    public function updateHousehold(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        if ($scope->hasHousehold()) {
            $name = trim(is_scalar($body['name'] ?? null) ? (string) $body['name'] : '');
            if ($name !== '') {
                $this->households->rename((int) $scope->householdId, mb_substr($name, 0, 100));
            }

            $this->applyRoleChanges($scope->householdId, $scope->userId, $body);
        }

        $this->flash('success', 'Household settings saved.');

        return $this->redirectAfterWrite($request, $response, '/settings');
    }

    public function updateInstance(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $name = trim(is_scalar($body['instance_name'] ?? null) ? (string) $body['instance_name'] : '');
        if ($name !== '') {
            $this->settings->setInstanceName(mb_substr($name, 0, 100));
        }

        $rawCurrency = is_scalar($body['base_currency'] ?? null) ? (string) $body['base_currency'] : '';
        $currency = Currency::normalise($rawCurrency);
        $baseCurrencyChanged = Currency::isValidCode($currency) && $currency !== $this->settings->baseCurrency();
        if (Currency::isValidCode($currency)) {
            $this->settings->setBaseCurrency($currency);
        }

        $providerChanged = $this->applyRateProviderChange($body);

        // Cached rates are stored against one base and sourced from one
        // provider. Changing either makes every cached row answer a question
        // nobody asked, so they are dropped rather than left to expire.
        if ($baseCurrencyChanged || $providerChanged) {
            $this->rates->invalidate();
        }

        $isolation = IsolationMode::tryFrom(
            is_scalar($body['isolation_mode'] ?? null) ? (string) $body['isolation_mode'] : '',
        );
        if ($isolation !== null) {
            $this->settings->setIsolationMode($isolation);
        }

        $this->settings->setRegistrationAllowed(($body['allow_registration'] ?? '0') === '1');

        $this->flash('success', 'Instance settings saved.');

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
                $this->user($request)->id,
            );
            $this->flash('success', 'Trusted host added. Notifications may now reach it.');
        } catch (ValidationException $exception) {
            $this->flash('error', implode(' ', $exception->errors()));
        }

        return $this->redirectAfterWrite($request, $response, '/settings');
    }

    public function deleteTrustedHost(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->trustedHosts->remove((int) $id, $this->user($request)->id);
        $this->flash('success', 'Trusted host removed.');

        return $this->redirectAfterWrite($request, $response, '/settings');
    }

    /**
     * @param array<string, mixed> $body
     * @return bool Whether the provider actually changed.
     */
    private function applyRateProviderChange(array $body): bool
    {
        $requested = is_scalar($body['rate_provider'] ?? null) ? (string) $body['rate_provider'] : '';
        $changed = false;

        if ($this->rateProviders->has($requested) && $requested !== $this->rates->provider()->key()) {
            $this->settings->setRateProvider($requested);
            $changed = true;
        }

        // An empty field leaves the stored key alone: the input is rendered
        // blank every time (it is a secret and is never echoed back), so
        // treating blank as "clear it" would wipe the key on every save.
        $key = trim(is_scalar($body['rate_provider_key'] ?? null) ? (string) $body['rate_provider_key'] : '');
        if ($key !== '') {
            $this->settings->setRateProviderKey($key);
        } elseif (($body['clear_rate_provider_key'] ?? '') === '1') {
            $this->settings->setRateProviderKey('');
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyRoleChanges(?int $householdId, int $actingUserId, array $body): void
    {
        if ($householdId === null) {
            return;
        }

        $roles = $body['roles'] ?? null;
        if (!is_array($roles)) {
            return;
        }

        foreach ($roles as $userId => $roleValue) {
            $userId = (int) $userId;
            $role = Role::tryFrom(is_scalar($roleValue) ? (string) $roleValue : '');

            // An owner may not demote themselves: doing so could leave the
            // household with nobody able to administer it.
            if ($role === null || $userId <= 0 || $userId === $actingUserId) {
                continue;
            }

            $this->memberships->updateRole($householdId, $userId, $role);
        }
    }
}
