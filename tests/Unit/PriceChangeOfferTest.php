<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Entity\PriceChange;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The one rule every surface reads to call a step the end of an offer.
 */
final class PriceChangeOfferTest extends TestCase
{
    public function testTheRowAfterAnIntroPriceEndsTheOffer(): void
    {
        $intro = $this->row(1, 599, isPromotional: true);
        $full = $this->row(2, 1199);

        self::assertTrue($full->endsOfferFrom($intro));
        // Still a rise as far as the price is concerned; the wording is what
        // the flag changes.
        self::assertTrue($full->isRiseFrom($intro));
    }

    public function testAnOrdinaryRiseEndsNoOffer(): void
    {
        self::assertFalse($this->row(2, 1199)->endsOfferFrom($this->row(1, 599)));
        self::assertFalse($this->row(1, 599)->endsOfferFrom(null));
    }

    public function testASecondIntroPriceContinuesTheOffer(): void
    {
        self::assertFalse(
            $this->row(2, 799, isPromotional: true)->endsOfferFrom($this->row(1, 599, isPromotional: true)),
        );
    }

    public function testACurrencyChangeIsNotTheEndOfAnOffer(): void
    {
        self::assertFalse(
            $this->row(2, 1399, currency: 'EUR')->endsOfferFrom($this->row(1, 599, isPromotional: true)),
        );
    }

    private function row(int $id, int $minor, bool $isPromotional = false, string $currency = 'GBP'): PriceChange
    {
        return new PriceChange(
            id: $id,
            subscriptionId: 1,
            price: Money::of($minor, $currency),
            effectiveFrom: new DateTimeImmutable('2026-01-0' . $id),
            source: $id === 1 ? PriceChangeSource::Initial : PriceChangeSource::Scheduled,
            note: null,
            createdByName: null,
            createdAt: new DateTimeImmutable('2026-01-01'),
            isPromotional: $isPromotional,
        );
    }
}
