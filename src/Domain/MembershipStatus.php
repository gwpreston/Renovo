<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Whether a membership has been taken up.
 *
 * Two values and no more. A provisioned member is `pending` from the moment
 * the Owner adds them until they accept the invite or set their first
 * password, and `active` thereafter; there is no `revoked` here, because a
 * revoked login is a state of the account rather than of one membership and
 * lives on `users.disabled_at`. Two columns able to disagree about whether
 * somebody may sign in is a bug waiting for the day they do.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Pending = 'pending';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Active;
    }

    public function labelKey(): string
    {
        return 'membership_status.' . $this->value;
    }
}
