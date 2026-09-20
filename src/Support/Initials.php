<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one or two letters that stand in for a face.
 *
 * Here rather than on User because the member list works from a read model
 * that is not a User, and two copies of this would drift the first time
 * somebody decides a middle name should count.
 *
 * Taken from the display name, never the email address: a member provisioned
 * without a mailbox has a placeholder address whose letters mean nothing.
 */
final class Initials
{
    public static function of(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr($words[0], 0, 1));

        if (count($words) === 1) {
            return $first;
        }

        return $first . mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1));
    }
}
