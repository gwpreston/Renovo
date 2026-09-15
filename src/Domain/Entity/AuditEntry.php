<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\AuditAction;
use DateTimeImmutable;

/**
 * One line of the audit log, as read back for display.
 *
 * The labels are the values captured when the entry was written, not a join:
 * the account they describe may since have been renamed or deleted, and the
 * entry has to keep meaning what it meant at the time.
 */
final class AuditEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly int $id,
        public readonly DateTimeImmutable $occurredAt,
        public readonly AuditAction $action,
        public readonly ?int $actorUserId,
        public readonly ?string $actorLabel,
        public readonly ?int $targetUserId,
        public readonly ?string $targetLabel,
        public readonly ?int $householdId,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly array $context,
    ) {
    }

    public function actorName(): string
    {
        return $this->actorLabel ?? 'Unknown';
    }

    /**
     * A one-line summary of the context, for the table's detail column.
     */
    public function summary(): string
    {
        $parts = [];
        foreach ($this->context as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }

            if (is_array($value)) {
                $value = implode(', ', array_map(strval(...), $value));
            }

            if (!is_scalar($value)) {
                continue;
            }

            $parts[] = str_replace('_', ' ', (string) $key) . ': ' . (string) $value;
        }

        return implode(' · ', $parts);
    }
}
