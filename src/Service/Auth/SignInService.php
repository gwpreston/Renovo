<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Repository\MembershipRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\AuditLogService;

/**
 * The one place a browser becomes signed in.
 *
 * Three routes end here — password with no second factor, password followed by
 * a second factor, and a usernameless passkey — and every one of them needs the
 * same four things done in the same order. A second implementation of that
 * sequence is how one of them ends up missing the session regeneration, so
 * there is only ever one.
 *
 * The order matters:
 *  1. Regenerate the session id, so a fixed id planted before sign-in is not
 *     the id that ends up authenticated.
 *  2. Rotate the CSRF token, so a token captured before the privilege change
 *     cannot be replayed after it.
 *  3. Only then write the user id, which is what every authenticated route
 *     keys off.
 *  4. Record it, with the method used, because "signed in with a passkey" and
 *     "signed in with a password and a code" are different facts to anyone
 *     reading the log afterwards.
 */
final class SignInService
{
    public const METHOD_PASSWORD = 'password';
    public const METHOD_PASSWORD_TOTP = 'password+totp';
    public const METHOD_PASSWORD_PASSKEY = 'password+passkey';
    public const METHOD_PASSWORD_RECOVERY = 'password+recovery_code';
    public const METHOD_PASSKEY = 'passkey';

    public function __construct(
        private readonly SessionInterface $session,
        private readonly CsrfTokenManager $csrf,
        private readonly MembershipRepository $memberships,
        private readonly AuditLogService $audit,
    ) {
    }

    public function establish(User $user, string $method): void
    {
        $this->session->regenerate();
        $this->csrf->rotate();

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $user->id);

        $memberships = $this->memberships->findAllForUser($user->id);
        if ($memberships !== []) {
            $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $memberships[0]->householdId);
        }

        $this->audit->record(AuditAction::LoginSucceeded, $user, ['method' => $method]);
    }

    /**
     * End the session and record it.
     *
     * The audit entry is written before the session is cleared, because
     * afterwards there is nobody left to attribute it to.
     */
    public function signOut(User $user): void
    {
        $this->audit->record(AuditAction::LoggedOut, $user);

        $this->session->clear();
        $this->session->regenerate();
    }
}
