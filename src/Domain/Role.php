<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A user's role within one household. Instance-level administration is a
 * separate flag on the user and grants no household data access.
 */
enum Role: string
{
    case OwnerAdmin = 'owner_admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function canRead(): bool
    {
        return true;
    }

    public function canWrite(): bool
    {
        return $this !== self::Viewer;
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
        return [self::OwnerAdmin, self::Editor, self::Viewer];
    }
}
