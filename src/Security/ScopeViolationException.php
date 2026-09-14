<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Thrown when a write targets a row outside the caller's scope.
 *
 * This is a hard failure rather than a silent no-op: a scoped UPDATE or DELETE
 * that matches zero rows means either the row does not exist or it belongs to
 * somebody else, and the caller must not be allowed to proceed as if it had
 * worked. Controllers translate it into a 404 so the two cases stay
 * indistinguishable to the client.
 */
final class ScopeViolationException extends RuntimeException
{
    public static function forRow(string $table, int $id): self
    {
        return new self(sprintf('Row %d of "%s" is not within the current scope.', $id, $table));
    }
}
