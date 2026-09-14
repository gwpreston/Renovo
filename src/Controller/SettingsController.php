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
use App\Service\InstanceSettingsService;
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
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        return $this->render($request, $response, 'settings/index.twig', [
            'household' => $scope->hasHousehold() ? $this->households->findById((int) $scope->householdId) : null,
            'members' => $scope->hasHousehold()
                ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
                : [],
            'roles' => Role::assignable(),
            'themes' => self::THEMES,
            'currencies' => Currency::common(),
            'isolation_modes' => IsolationMode::cases(),
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
        if (Currency::isValidCode($currency)) {
            $this->settings->setBaseCurrency($currency);
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
