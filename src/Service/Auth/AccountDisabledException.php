<?php

declare(strict_types=1);

namespace App\Service\Auth;

use RuntimeException;

/**
 * Thrown when a sign-in reaches the last step with a revoked account.
 *
 * This is a backstop, not the error the user is meant to see. Each sign-in
 * route checks `isDisabled()` itself and answers with a sentence in the right
 * shape for it — a form error, a 403 in JSON — because a disabled account is
 * an ordinary thing to encounter, not an exceptional one.
 *
 * What this exists for is the route nobody has written yet. `establish()` is
 * the one place all of them end up, so the check there is the one that cannot
 * be forgotten by a fourth sign-in path added in a later phase. It fails
 * closed: an uncaught throw is a 500, which is a bad page but not a signed-in
 * session.
 */
final class AccountDisabledException extends RuntimeException
{
    public static function forUser(int $userId): self
    {
        return new self(sprintf('Account %d cannot sign in: its login has been revoked.', $userId));
    }
}
