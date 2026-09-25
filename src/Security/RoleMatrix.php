<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\CapabilityFence;
use App\Domain\CapabilityGrant;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\RoleCapability;

/**
 * What each role may do, generated rather than written down.
 *
 * Every cell is answered by the two things that actually decide it:
 * `PermissionService`, for whether the role may do the thing at all, and the
 * `Scope` the role would be given on this instance, for whether it may do it
 * only to its own rows. So the page cannot claim a permission the server does
 * not enforce — a change to either one changes the table the same day, and
 * the test that pins the table fails.
 *
 * The scope is built with the instance-admin flag off. The table says what a
 * *household role* confers; the flag is a separate grant that would otherwise
 * light cells that have nothing to do with the household.
 *
 * In ISOLATED mode every role's view and edit cells read "Own only", an
 * Owner's included. That is what the scoping layer does there, and a table
 * that said otherwise would describe a power the repository does not grant.
 *
 * @phpstan-type Cell array{role: Role, grant: CapabilityGrant}
 * @phpstan-type Row array{capability: RoleCapability, cells: list<Cell>}
 */
final class RoleMatrix
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /**
     * Every capability, and every assignable role's answer to it.
     *
     * @return list<Row>
     */
    public function rows(IsolationMode $mode): array
    {
        $rows = [];
        foreach (RoleCapability::cases() as $capability) {
            $cells = [];
            foreach (Role::assignable() as $role) {
                $cells[] = ['role' => $role, 'grant' => $this->grant($role, $mode, $capability)];
            }

            $rows[] = ['capability' => $capability, 'cells' => $cells];
        }

        return $rows;
    }

    /**
     * One cell.
     *
     * @throws \LogicException when the role holds some of the row's
     *                         permissions and not others. The row would then
     *                         be a claim about part of what it names, and the
     *                         grouping in `RoleCapability` is what is wrong.
     */
    public function grant(Role $role, IsolationMode $mode, RoleCapability $capability): CapabilityGrant
    {
        // The household id is a placeholder: nothing here reads a row, and a
        // permission answer does not depend on which household it is asked in.
        $scope = Scope::forMember(0, false, 0, $role, $mode);

        $held = [];
        foreach ($capability->permissions() as $permission) {
            $held[] = $this->permissions->allows($scope, $permission);
        }

        if (count(array_unique($held)) > 1) {
            throw new \LogicException(sprintf(
                'The %s role holds only some of the permissions behind "%s".',
                $role->value,
                $capability->value,
            ));
        }

        if (!$held[0]) {
            return CapabilityGrant::No;
        }

        $fenced = match ($capability->fence()) {
            CapabilityFence::None => false,
            CapabilityFence::Reads => $scope->restrictsReadsToOwner(),
            CapabilityFence::Writes => $scope->restrictsWritesToOwner(),
        };

        return $fenced ? CapabilityGrant::OwnOnly : CapabilityGrant::Yes;
    }
}
