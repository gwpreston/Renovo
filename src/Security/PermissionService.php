<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Permission;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;

/**
 * The only place roles are turned into yes/no answers.
 *
 * Templates may call `allows()` to decide whether to render a control, but
 * that is a convenience, not access control: every mutating route also runs
 * `assert()` server-side, so hiding a button and removing the ability to use
 * it are two separate, independent things.
 */
final class PermissionService
{
    public function allows(Scope $scope, Permission $permission): bool
    {
        return match ($permission) {
            Permission::ViewSubscriptions => $scope->canRead(),

            Permission::CreateSubscription,
            Permission::UpdateSubscription,
            Permission::DeleteSubscription,
            Permission::ManageCategories,
            Permission::ManageTags,
            Permission::ManageBudgets,
            Permission::ManagePrices,
            Permission::ManageSplits,
            Permission::RecordUsage,
            Permission::BulkEdit => $scope->canWrite(),

            Permission::ManageHousehold => $scope->canManageHousehold(),

            // Two independent routes to the log, and the repository decides
            // which rows each one gets: instance-wide for an administrator,
            // household-scoped for an Owner. Neither implies the other.
            Permission::ViewAuditLog => $scope->isInstanceAdmin || $scope->canManageHousehold(),

            // Instance administration is the one permission the household role
            // has no say in — and equally, it grants no household data access.
            Permission::ManageInstance => $scope->isInstanceAdmin,
        };
    }

    /**
     * @throws HttpForbiddenException
     */
    public function assert(ServerRequestInterface $request, Scope $scope, Permission $permission): void
    {
        if ($this->allows($scope, $permission)) {
            return;
        }

        throw new HttpForbiddenException(
            $request,
            sprintf('Your role does not permit "%s".', $permission->value),
        );
    }
}
