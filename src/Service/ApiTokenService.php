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
 * Issuing, presenting, rotating and revoking API tokens.
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

    /**
     * The name the calendar screen issues its feed token under.
     *
     * Fixed rather than translated: it is how the screen finds its own token
     * again, and a name that followed the member's language would stop
     * matching the day they changed it.
     */
    public const FEED_TOKEN_NAME = 'Calendar feed';

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
            throw ValidationException::field('name', 'error.token.name_required');
        }
        if (mb_strlen($name) > 100) {
            throw ValidationException::field('name', 'error.name.too_long_100');
        }
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            throw ValidationException::field('expires_at', 'error.token.expiry_past');
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

    /**
     * Replace a token with a new one that can do the same things.
     *
     * **This is a rotation, not a duplication.** The old secret stops working
     * the instant the new one is returned. That is the whole point: a reissue
     * that left the previous credential live would mean every rotation doubled
     * the number of secrets that can reach the account, and the one thing a
     * person reissuing a token is usually trying to do is stop trusting the old
     * one.
     *
     * Name, abilities and expiry carry over — a reissue that lost them would be
     * `issue()` with extra steps. The expiry is the awkward one: `issue()`
     * refuses a date already in the past, so a token that has expired cannot be
     * reissued into the same expiry. Rather than silently granting it a longer
     * life than it had, this refuses, and the caller offers reissue only on a
     * token that is still usable.
     *
     * @return string The full new token. As with `issue()`, this is the only
     *                time it exists.
     * @throws ValidationException
     */
    public function reissue(User $user, int $tokenId): string
    {
        $existing = $this->tokens->findForUser($user->id, $tokenId);
        if ($existing === null) {
            throw ValidationException::field('token', 'error.token.not_found');
        }

        if (!$existing->isUsable($this->clock->now())) {
            throw ValidationException::field('token', 'error.token.not_reissuable');
        }

        // Revoked first. If issuing the replacement fails, the worst outcome is
        // a revoked token and no new one — which is a recoverable inconvenience,
        // where the other order risks two live secrets and no record of it.
        $this->tokens->revoke($user->id, $tokenId);

        $this->audit->record(AuditAction::ApiTokenRevoked, $user, [
            'token_id' => $tokenId,
            'reason' => 'reissued',
        ], $existing->householdId);

        return $this->issue(
            $user,
            $existing->householdId,
            $existing->name,
            $existing->abilities,
            $existing->expiresAt,
        );
    }

    /**
     * The link the calendar screen hands out, if there is a live one.
     *
     * A feed token is an ordinary read-only token under a reserved name, for
     * the household it reads. Nothing else marks it: the secret is a hash like
     * every other, so this can say when the link was made and last fetched but
     * never what it was — which is why the calendar shows the URL only once.
     */
    public function feedToken(User $user, ?int $householdId): ?ApiToken
    {
        foreach ($this->feedTokens($user, $householdId) as $token) {
            return $token;
        }

        return null;
    }

    /**
     * Replace the calendar feed link: every live feed token for this household
     * is revoked, and a new one issued.
     *
     * Revoked first, for `reissue()`'s reason — a failure leaves no link rather
     * than two. Read tokens the member issued themselves on the tokens page are
     * left alone even if they paste one into a calendar; only the reserved name
     * is this screen's to replace.
     *
     * @return string The full new token, shown once.
     */
    public function replaceFeedToken(User $user, ?int $householdId): string
    {
        foreach ($this->feedTokens($user, $householdId) as $token) {
            if ($this->tokens->revoke($user->id, $token->id)) {
                $this->audit->record(AuditAction::ApiTokenRevoked, $user, [
                    'token_id' => $token->id,
                    'reason' => 'feed_replaced',
                ], $householdId);
            }
        }

        return $this->issue($user, $householdId, self::FEED_TOKEN_NAME, TokenAbility::Read);
    }

    /**
     * @return list<ApiToken> Newest first.
     */
    private function feedTokens(User $user, ?int $householdId): array
    {
        $now = $this->clock->now();

        return array_values(array_filter(
            $this->tokens->findAllForUser($user->id),
            static fn (ApiToken $token): bool => $token->name === self::FEED_TOKEN_NAME
                && $token->abilities === TokenAbility::Read
                && $token->householdId === $householdId
                && $token->isUsable($now),
        ));
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
        if ($user === null || $user->isDisabled()) {
            // A revoked login is revoked everywhere. The token itself is still
            // valid and starts working again if the account is restored, which
            // is the right behaviour for a temporary suspension — but while the
            // account is disabled it answers exactly as an unknown token does.
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
