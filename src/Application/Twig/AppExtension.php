<?php

declare(strict_types=1);

namespace App\Application\Twig;

use App\Domain\BillingCycle;
use App\Domain\IsolationMode;
use App\Domain\NoticePeriod;
use App\Domain\Permission;
use App\Domain\Role;
use App\Domain\SubscriptionType;
use App\Security\CsrfTokenManager;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Support\MoneyFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The small set of helpers templates are allowed to use.
 *
 * Formatting and permission *questions* live here; no decision does. `can()`
 * exists so a template can hide a control the user cannot use, and is never
 * the thing that stops them using it — that is RequirePermissionMiddleware.
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly PermissionService $permissions,
        private readonly CsrfTokenManager $csrf,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_token', fn (): string => $this->csrf->token()),
            new TwigFunction('csrf_field', $this->csrfField(...), ['is_safe' => ['html']]),
            new TwigFunction('can', $this->can(...)),
            new TwigFunction('cycle_label', $this->cycleLabel(...)),
            new TwigFunction('type_label', $this->typeLabel(...)),
            new TwigFunction('role_label', $this->roleLabel(...)),
            new TwigFunction('isolation_label', $this->isolationLabel(...)),
            new TwigFunction('notice_label', $this->noticeLabel(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->formatMoney(...)),
        ];
    }

    public function formatMoney(?int $amountMinor, string $currency): string
    {
        return $this->money->formatMinor($amountMinor ?? 0, $currency);
    }

    public function csrfField(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            CsrfTokenManager::FIELD_NAME,
            htmlspecialchars($this->csrf->token(), ENT_QUOTES, 'UTF-8'),
        );
    }

    public function can(?Scope $scope, string $permission): bool
    {
        $resolved = Permission::tryFrom($permission);

        return $scope !== null && $resolved !== null && $this->permissions->allows($scope, $resolved);
    }

    public function cycleLabel(?string $cycle, ?int $cycleDays = null): string
    {
        return match (BillingCycle::tryFromString($cycle)) {
            BillingCycle::Weekly => 'Weekly',
            BillingCycle::Monthly => 'Monthly',
            BillingCycle::Quarterly => 'Quarterly',
            BillingCycle::Yearly => 'Yearly',
            BillingCycle::CustomDays => $cycleDays === null
                ? 'Custom'
                : sprintf('Every %d days', $cycleDays),
            null => '—',
        };
    }

    public function typeLabel(?string $type): string
    {
        return match (SubscriptionType::tryFrom($type ?? '')) {
            SubscriptionType::Recurring => 'Recurring',
            SubscriptionType::OneOff => 'One-off',
            SubscriptionType::Lifetime => 'Lifetime',
            null => '—',
        };
    }

    public function roleLabel(?string $role): string
    {
        return match (Role::tryFrom($role ?? '')) {
            Role::OwnerAdmin => 'Owner / Admin',
            Role::Editor => 'Editor',
            Role::Viewer => 'Viewer',
            null => '—',
        };
    }

    public function isolationLabel(?string $mode): string
    {
        return match (IsolationMode::tryFrom($mode ?? '')) {
            IsolationMode::Shared => 'Shared — everyone in a household sees its subscriptions',
            IsolationMode::Isolated => 'Isolated — each member sees only their own',
            null => '—',
        };
    }

    public function noticeLabel(?int $amount, ?string $unit): string
    {
        if ($amount === null || $amount <= 0) {
            return 'None';
        }

        $label = match ($unit) {
            NoticePeriod::UNIT_WEEKS => 'week',
            NoticePeriod::UNIT_MONTHS => 'month',
            default => 'day',
        };

        return sprintf('%d %s%s', $amount, $label, $amount === 1 ? '' : 's');
    }
}
