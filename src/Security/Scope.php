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
 *
 * **Reading and writing are restricted by different questions**, and the two
 * are never collapsed into one. `restrictsReadsToOwner()` is the instance's
 * isolation mode; `restrictsWritesToOwner()` is that *or* a role that writes
 * only its own rows. A Contributor on a SHARED instance sees the whole
 * household and may change only their part of it, which is a sentence that
 * cannot be said with a single flag — and the day it was said with one, the
 * screen would show them an empty list.
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

    public function canManageShared(): bool
    {
        return $this->role?->canManageShared() ?? false;
    }

    /**
     * True when this user may only *see* rows they own.
     *
     * The instance's isolation mode and nothing else. A role never narrows what
     * somebody can read — a Contributor is a member of the household with the
     * same sight of it as a Viewer, and confining their reads because their
     * writes are confined would be a different feature nobody asked for.
     */
    public function restrictsReadsToOwner(): bool
    {
        return $this->isolationMode->restrictsToOwner();
    }

    /**
     * True when this user may only *change* rows they own.
     *
     * Two independent sources, OR-ed, because they are the same restriction
     * arriving from different directions: the instance says "nobody touches
     * anybody else's", or the role says "this person doesn't". A Contributor on
     * a SHARED instance and an Editor on an ISOLATED one are fenced identically,
     * and the repository has one rule to apply rather than two to keep in step.
     */
    public function restrictsWritesToOwner(): bool
    {
        return $this->isolationMode->restrictsToOwner()
            || ($this->role?->writesOwnRowsOnly() ?? false);
    }

    /**
     * Whether a row this scope can already see is also one it may *change*.
     *
     * The same rule `AbstractScopedRepository::scopePredicate()` compiles into
     * SQL — the household, and the owner too when the instance is ISOLATED or
     * the role writes only its own — asked here about a row that has already
     * been read. It exists because reads are wider than writes: a participant
     * in a shared cost can see the subscription they help pay for without being
     * able to touch it, so a screen that offered them a Pause button would be
     * offering an error message. A Contributor is the other case, and the wider
     * one: they see every row in the household and own only some of them.
     *
     * This decides whether a control is *drawn*. It is not what refuses the
     * request if one is forged — that is the repository, which applies the rule
     * to the UPDATE itself, and it stays the only thing standing between a
     * crafted POST and somebody else's row.
     */
    public function mayWriteRow(int $householdId, int $ownerUserId): bool
    {
        if ($this->householdId !== $householdId) {
            return false;
        }

        return !$this->restrictsWritesToOwner() || $ownerUserId === $this->userId;
    }
}
