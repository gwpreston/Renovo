<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\User;
use App\Domain\TokenAbility;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Issuing, presenting and revoking API tokens.
 *
 * The format is `rnv_<public id>_<secret>`:
 *
 *  - `rnv_` makes a leaked token recognisable in a log or a paste, which is
 *    what lets secret scanners find one before somebody else does.
 *  - the public id is 24 hex characters, stored in the clear and indexed. It
 *    identifies *which* token is being presented.
 *  - the secret is 64 hex characters and is stored only as a SHA-256. It is
 *    shown once, at issue, and is not recoverable afterwards.
 *
 * Splitting the two halves is what keeps verification to a single indexed row
 * read. The alternative — one opaque string, hashed and compared against every
 * row — either scans the table on each request or forces a fast, unsalted hash
 * to stay usable. SHA-256 is correct here precisely *because* the secret is 256
 * bits of `random_bytes`: there is no dictionary to stretch against, unlike a
 * password, so a slow KDF would buy nothing and cost every API call.
 */
final class ApiTokenService
{
    public const PREFIX = 'rnv';

    private const PUBLIC_ID_BYTES = 12;
    private const SECRET_BYTES = 32;

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<ApiToken>
     */
    public function listFor(User $user): array
    {
        return $this->tokens->findAllForUser($user->id);
    }

    /**
     * Issue a token.
     *
     * @return string The full token. This is the only time it exists.
     * @throws ValidationException
     */
    public function issue(
        User $user,
        ?int $householdId,
        string $name,
        TokenAbility $abilities,
        ?DateTimeImmutable $expiresAt = null,
    ): string {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::field('name', 'Give the token a name so you can recognise it later.');
        }
        if (mb_strlen($name) > 100) {
            throw ValidationException::field('name', 'Name must be 100 characters or fewer.');
        }
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            throw ValidationException::field('expires_at', 'Choose an expiry date in the future.');
        }

        $publicId = bin2hex(random_bytes(self::PUBLIC_ID_BYTES));
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        $this->tokens->create(
            $user->id,
            $householdId,
            $name,
            $publicId,
            $this->hash($secret),
            $abilities,
            $expiresAt,
        );

        $this->audit->record(AuditAction::ApiTokenIssued, $user, [
            'name' => $name,
            'public_id' => $publicId,
            'abilities' => $abilities->value,
            'expires_at' => $expiresAt?->format('Y-m-d'),
        ], $householdId);

        return sprintf('%s_%s_%s', self::PREFIX, $publicId, $secret);
    }

    public function revoke(User $user, int $tokenId): bool
    {
        $revoked = $this->tokens->revoke($user->id, $tokenId);

        if ($revoked) {
            $this->audit->record(AuditAction::ApiTokenRevoked, $user, ['token_id' => $tokenId]);
        }

        return $revoked;
    }

    /**
     * Resolve a presented token into the token row and the user it belongs to.
     *
     * Every failure returns null and none of them says why. A caller holding a
     * revoked token and a caller holding a made-up one learn the same thing,
     * which is nothing.
     *
     * @return array{0: ApiToken, 1: User}|null
     */
    public function authenticate(string $presented): ?array
    {
        $parts = explode('_', trim($presented));
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        [, $publicId, $secret] = $parts;
        if ($publicId === '' || $secret === '') {
            return null;
        }

        $token = $this->tokens->findByCredentials($publicId, $this->hash($secret));
        if ($token === null || !$token->isUsable($this->clock->now())) {
            return null;
        }

        $user = $this->users->findById($token->userId);
        if ($user === null) {
            return null;
        }

        $this->tokens->touch($token->id);

        return [$token, $user];
    }

    private function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
