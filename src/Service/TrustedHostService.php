<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Http\IpAddress;
use App\Http\TrustedTargets;
use App\Repository\TrustedHostRepository;
use Psr\Log\LoggerInterface;

/**
 * The administrator's list of destinations the SSRF guard will allow through.
 *
 * This is the one deliberate hole in the outbound network rules, so it is built
 * to be a hole somebody *chose*: every entry is added by an instance
 * administrator, every addition and removal is logged with who did it, and
 * nothing is on the list to begin with. The guard consults it; it never asks
 * the guard to relax anything else.
 *
 * Patterns are host names, dotted suffixes, bare addresses or CIDR blocks.
 * Anything else is refused here rather than being stored and silently ignored
 * by the guard — a rule an operator believes is in force but is not is worse
 * than no rule.
 */
final class TrustedHostService implements TrustedTargets
{
    /** @var list<string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly TrustedHostRepository $repository,
        private readonly AuditLogService $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function entries(): array
    {
        return $this->cache ??= $this->repository->patterns();
    }

    /**
     * @return list<array{id: int, pattern: string, note: string|null,
     *                    created_at: \DateTimeImmutable, created_by: string|null}>
     */
    public function all(): array
    {
        return $this->repository->findAll();
    }

    /**
     * @throws ValidationException
     */
    public function add(string $pattern, ?string $note, ?User $actor): void
    {
        $pattern = strtolower(trim($pattern));

        if ($pattern === '') {
            throw ValidationException::field('pattern', 'Enter a host name, address or CIDR range.');
        }

        if (mb_strlen($pattern) > 255) {
            throw ValidationException::field('pattern', 'That is too long to be a host or range.');
        }

        if (!$this->isValidPattern($pattern)) {
            throw ValidationException::field(
                'pattern',
                'Enter a host name (gotify.lan), a suffix (.lan), an address (192.168.1.10) '
                . 'or a range (100.64.0.0/10).',
            );
        }

        if ($this->repository->exists($pattern)) {
            throw ValidationException::field('pattern', 'That is already on the list.');
        }

        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 255);

        $this->repository->add($pattern, $note, $actor?->id);
        $this->cache = null;

        $this->audit->record(AuditAction::TrustedHostAdded, $actor, ['pattern' => $pattern]);

        // Logged because this widens what the server can be made to connect to.
        // The record of who opened it, and when, is the only thing that makes
        // the exception reviewable afterwards.
        $this->logger->notice('Trusted host added', [
            'pattern' => $pattern,
            'user_id' => $actor?->id,
        ]);
    }

    public function remove(int $id, ?User $actor): void
    {
        $this->repository->delete($id);
        $this->cache = null;

        $this->audit->record(AuditAction::TrustedHostRemoved, $actor, ['id' => $id]);

        $this->logger->notice('Trusted host removed', ['id' => $id, 'user_id' => $actor?->id]);
    }

    private function isValidPattern(string $pattern): bool
    {
        if (IpAddress::isValidRange($pattern)) {
            return true;
        }

        $host = ltrim($pattern, '.');

        // A host name, or a dotted suffix meaning "and anything under it".
        return $host !== ''
            && mb_strlen($host) <= 253
            && preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) === 1;
    }
}
