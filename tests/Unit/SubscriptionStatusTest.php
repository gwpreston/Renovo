<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\AlertType;
use App\Domain\SubscriptionStatus;
use App\Tests\Support\SubscriptionFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The one word for where a subscription stands, derived in one place and in
 * one order: Cancelled, then Paused, then Trial, then Active.
 */
final class SubscriptionStatusTest extends TestCase
{
    public function testTheOrderIsCancelledPausedTrialActive(): void
    {
        $cancelled = new DateTimeImmutable('2026-06-01');

        self::assertSame(
            SubscriptionStatus::Cancelled,
            SubscriptionFactory::make(isTrial: true, isActive: false, cancelledAt: $cancelled)->status(),
            'a cancelled trial is cancelled',
        );
        self::assertSame(
            SubscriptionStatus::Paused,
            SubscriptionFactory::make(isTrial: true, isActive: false)->status(),
            'a paused trial is paused',
        );
        self::assertSame(SubscriptionStatus::Trial, SubscriptionFactory::make(isTrial: true)->status());
        self::assertSame(SubscriptionStatus::Active, SubscriptionFactory::make()->status());
    }

    public function testEachStateHasACatalogueLabel(): void
    {
        $catalogue = require dirname(__DIR__, 2) . '/translations/en.php';

        foreach (SubscriptionStatus::cases() as $status) {
            self::assertArrayHasKey($status->labelKey(), $catalogue);
        }
    }

    public function testAPriceChangeIsNotAnchoredToLeadTimes(): void
    {
        self::assertFalse(AlertType::PriceChange->usesLeadTimes());
        self::assertFalse(AlertType::BudgetExceeded->usesLeadTimes());
        self::assertTrue(AlertType::Renewal->usesLeadTimes());
    }
}
