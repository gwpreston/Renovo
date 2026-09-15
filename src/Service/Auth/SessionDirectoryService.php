<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Domain\AuditAction;
use App\Domain\Entity\ActiveSession;
use App\Domain\Entity\User;
use App\Repository\SessionRepository;
use App\Service\AuditLogService;
use App\Support\Clock;

/**
 * "Where am I signed in?", and the ability to answer "not there any more".
 *
 * Revocation is a row delete, and that is the whole mechanism: the session
 * handler reads the row on the next request, finds nothing, and the
 * authentication middleware sends that browser to the login page. There is no
 * flag to honour, no token list to consult and nothing that keeps working until
 * a cache expires — which is what "revoke" has to mean to be worth offering.
 */
final class SessionDirectoryService
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<ActiveSession>
     */
    public function listFor(User $user, string $currentSessionId): array
    {
        return $this->sessions->findActiveForUser($user->id, $currentSessionId, $this->clock->now());
    }

    /**
     * Revoke one session. The current one is never a candidate — signing
     * yourself out belongs on the logout button, not in a list of devices.
     *
     * @return bool Whether a session was actually removed.
     */
    public function revoke(User $user, string $handle, string $currentSessionId): bool
    {
        $removed = $this->sessions->deleteByHandle($user->id, $handle, $currentSessionId);

        if ($removed > 0) {
            $this->audit->record(AuditAction::SessionRevoked, $user, ['sessions' => $removed]);
        }

        return $removed > 0;
    }

    public function revokeAllOthers(User $user, string $currentSessionId): int
    {
        $removed = $this->sessions->deleteAllExcept($user->id, $currentSessionId);

        if ($removed > 0) {
            $this->audit->record(AuditAction::SessionsRevokedAll, $user, ['sessions' => $removed]);
        }

        return $removed;
    }

    /**
     * Used after a credential changes: everything but the session that made the
     * change goes. A password reset or a second factor being removed should not
     * leave a session that predates it still signed in.
     */
    public function revokeAllOthersSilently(int $userId, string $currentSessionId): int
    {
        return $this->sessions->deleteAllExcept($userId, $currentSessionId);
    }
}
