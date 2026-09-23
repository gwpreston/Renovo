<?php

declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * What a subscription is paid with. A label with a picture: it holds no
 * amount, moves no money and stores no credential.
 */
final class PaymentMethod
{
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly string $name,
        public readonly ?string $colour,
        public readonly ?string $icon,
        public readonly ?string $logoPath,
    ) {
    }
}
