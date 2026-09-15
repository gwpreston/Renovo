<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

/**
 * A registered passkey or security key, as the settings page shows it.
 *
 * `record` is the library's serialisation and is what verification runs
 * against; everything else on here exists so the list can be rendered and a
 * credential recognised without deserialising anything.
 */
final class WebAuthnCredential
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $credentialId,
        public readonly string $record,
        public readonly string $name,
        public readonly ?string $aaguid,
        public readonly array $transports,
        public readonly int $signCount,
        public readonly bool $isDiscoverable,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $lastUsedAt,
    ) {
    }

    /**
     * A rough description of how the key is carried, for the list. Not a
     * security property — the browser reports it and an authenticator may
     * report nothing at all.
     */
    public function transportSummary(): string
    {
        return $this->transports === [] ? 'Unknown' : implode(', ', $this->transports);
    }
}
