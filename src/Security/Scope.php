<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\IsolationMode;
use App\Domain\Role;

/**
 * Who is asking, and what they are therefore allowed to see.
 *
 * A Scope is built once per request by ScopeMiddleware and is a required
 * argument to every scoped repository call. There is no repository method that
 * reads or writes household data without one, which is what makes data
 * isolation structural rather than a rule each route has to remember.
 *
 * Note that `isInstanceAdmin` deliberately grants nothing here. Instance
 * administration covers users and global settings; it is not a household data
 * superuser. See PermissionService for what the flag does grant.
 */
final class Scope
{
    private function __construct(
        public readonly int $userId,
        public readonly bool $isInstanceAdmin,
        public readonly ?int $householdId,
        public readonly ?Role $role,
        public readonly IsolationMode $isolationMode,
    ) {
    }

    public static function forMember(
        int $userId,
        bool $isInstanceAdmin,
        int $householdId,
        Role $role,
        IsolationMode $isolationMode,
    ): self {
        return new self($userId, $isInstanceAdmin, $householdId, $role, $isolationMode);
    }

    /**
     * A user who belongs to no household — for example an instance admin who
     * has never joined one. Every scoped query returns nothing.
     */
    public static function withoutHousehold(
        int $userId,
        bool $isInstanceAdmin,
        IsolationMode $isolationMode,
    ): self {
        return new self($userId, $isInstanceAdmin, null, null, $isolationMode);
    }

    public function hasHousehold(): bool
    {
        return $this->householdId !== null;
    }

    public function canRead(): bool
    {
        return $this->role?->canRead() ?? false;
    }

    public function canWrite(): bool
    {
        return $this->role?->canWrite() ?? false;
    }

    public function canManageHousehold(): bool
    {
        return $this->role?->canManageHousehold() ?? false;
    }

    /**
     * True when the isolation mode limits this user to rows they own.
     */
    public function isOwnerRestricted(): bool
    {
        return $this->isolationMode->restrictsToOwner();
    }
}
