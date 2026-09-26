<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which of the avatar colours a member's placeholder is drawn in.
 *
 * Derived from the user id rather than stored: a member has one from the
 * moment their account exists, keeps it for as long as it does, and the
 * members who were here before avatars had colours get one too. The colours
 * themselves are theme tokens (`--avatar-N` and `--avatar-N-ink` in
 * assets/theme/tokens.json), so each is legible in every palette and theme —
 * a hex stored per member could promise neither.
 *
 * Ids are handed out in order, so a household's first members land on
 * neighbouring tones rather than wherever a hash happens to put them: the
 * first eight people in an instance never share a colour.
 */
final class AvatarTone
{
    /** How many tones tokens.json defines. */
    public const COUNT = 8;

    /**
     * 1..COUNT for a member, or null for no member at all (a subscription
     * whose owner has gone), which draws the neutral disc.
     */
    public static function of(?int $userId): ?int
    {
        if ($userId === null || $userId < 1) {
            return null;
        }

        return ($userId - 1) % self::COUNT + 1;
    }
}
