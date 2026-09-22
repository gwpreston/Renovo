<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A user's role within one household. Instance-level administration is a
 * separate flag on the user and grants no household data access.
 *
 * Four rungs, and the middle two are not a gradient of trust but two different
 * shapes of it. An **Editor** may change anything the household has. A
 * **Contributor** may change what is theirs and nothing else — they keep their
 * own subscriptions, prices, splits, invoices and budget, and they cannot touch
 * anybody else's row or the names the whole household shares. A **Viewer**
 * changes nothing.
 *
 * The Contributor's fence is not enforced here, and could not be: this enum
 * says *what kind* of writer somebody is, and the scoping layer turns that into
 * an owner clause on every UPDATE and DELETE. See `Scope::restrictsWritesToOwner()`
 * — a role that confines writes and an instance that confines them are the same
 * restriction arriving from two directions, and the repository applies one rule
 * for both.
 */
enum Role: string
{
    case OwnerAdmin = 'owner_admin';
    case Editor = 'editor';
    case Contributor = 'contributor';
    case Viewer = 'viewer';

    public function canRead(): bool
    {
        return true;
    }

    /**
     * Whether this role may change anything at all.
     *
     * True for a Contributor: they write, they are simply fenced to their own
     * rows. Asking this question is not asking *which* rows — that is
     * `writesOwnRowsOnly()`, and keeping them apart is what stops a permission
     * check from quietly becoming a visibility rule.
     */
    public function canWrite(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Whether this role's writes are confined to rows it owns.
     *
     * The Contributor's whole definition. It is deliberately a property of the
     * role rather than a permission: a permission answers "may they update a
     * subscription", which a Contributor may, and the fence is about *whose*.
     */
    public function writesOwnRowsOnly(): bool
    {
        return $this === self::Contributor;
    }

    /**
     * Whether this role may change what the whole household shares.
     *
     * Categories and tags are household-wide metadata — renaming one renames it
     * on everybody's subscriptions — and an import and a bulk edit both write
     * across rows by their nature. None of those can be fenced to one member's
     * own things, so they are not a Contributor's to do; they belong to the
     * roles that answer for the household as a whole.
     */
    public function canManageShared(): bool
    {
        return $this === self::OwnerAdmin || $this === self::Editor;
    }

    public function canManageHousehold(): bool
    {
        return $this === self::OwnerAdmin;
    }

    public function labelKey(): string
    {
        return 'role.' . $this->value;
    }

    /**
     * Roles ordered most to least privileged, for pick-lists.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::OwnerAdmin, self::Editor, self::Contributor, self::Viewer];
    }
}
