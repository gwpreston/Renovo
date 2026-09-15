<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Repository\RecoveryCodeRepository;
use App\Security\PasswordHasher;
use App\Service\AuditLogService;
use App\Support\Clock;

/**
 * The way back in when the second factor is gone.
 *
 * Deliberately not part of the TOTP service, even though that is where it
 * started. An account whose only second factor is a passkey is just as capable
 * of losing it — a phone goes missing, a laptop is replaced — and tying
 * recovery to authenticator apps alone would leave those accounts with no route
 * back at all on an instance that has no support desk to appeal to.
 *
 * The codes are hashed with the password hasher, so the only way to check one
 * is to try it against each unused hash; that is why there are ten of them and
 * not a thousand. Each is marked used rather than deleted, so a "recovery code
 * used" audit entry has a row to point at.
 */
final class RecoveryCodeService
{
    public const CODE_COUNT = 10;

    private const CODE_BYTES = 5;

    public function __construct(
        private readonly RecoveryCodeRepository $codes,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
    ) {
    }

    public function countUnused(int $userId): int
    {
        return $this->codes->countUnused($userId);
    }

    /**
     * Issue a fresh set, replacing any that exist.
     *
     * @return list<string> The plain codes. This is the only time they exist in
     *                      readable form; only hashes are kept.
     */
    public function issue(int $userId): array
    {
        $plain = [];
        $hashes = [];

        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            // Ten hex characters in two groups: enough entropy to be
            // unguessable, short enough to be written on paper without error.
            $raw = bin2hex(random_bytes(self::CODE_BYTES));
            $code = strtoupper(substr($raw, 0, 5) . '-' . substr($raw, 5));

            $plain[] = $code;
            $hashes[] = $this->hasher->hash($this->normalise($code));
        }

        $this->codes->replaceAll($userId, $hashes, $this->clock->now());

        return $plain;
    }

    /**
     * Issue codes only if the account has none left, and say whether it did.
     *
     * Used when a second factor is added by a route that was not asking about
     * recovery — registering a first passkey, say. An account that already has
     * usable codes keeps them: silently replacing a set the user has written
     * down would be worse than not issuing any.
     *
     * @return list<string> The new codes, or an empty list if none were needed.
     */
    public function issueIfNone(int $userId): array
    {
        return $this->countUnused($userId) > 0 ? [] : $this->issue($userId);
    }

    /**
     * Spend a code. Each one works exactly once.
     */
    public function redeem(User $user, string $submitted): bool
    {
        $candidate = $this->normalise($submitted);
        if ($candidate === '') {
            return false;
        }

        foreach ($this->codes->findUnused($user->id) as $row) {
            if (!$this->hasher->verify($candidate, $row['code_hash'])) {
                continue;
            }

            if (!$this->codes->markUsed($row['id'], $this->clock->now())) {
                // Another request spent it first.
                return false;
            }

            $this->audit->record(AuditAction::RecoveryCodeUsed, $user, [
                'remaining' => $this->countUnused($user->id),
            ]);

            return true;
        }

        return false;
    }

    public function clear(int $userId): void
    {
        $this->codes->deleteAll($userId);
    }

    /**
     * Compare codes without punctuation or case, so a code typed as it was
     * written down still works.
     */
    private function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}
