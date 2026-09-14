<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\BillingCycle;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\SubscriptionType;
use App\Domain\Entity\Subscription;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Month-end behaviour is the classic way a renewal date silently drifts. A
 * subscription billed on the 31st must not become a subscription billed on the
 * 28th after one February.
 */
final class BillingDateAdvanceTest extends TestCase
{
    public function testMonthlyAdvanceClampsToTheEndOfAShortMonth(): void
    {
        $january = new DateTimeImmutable('2026-01-31');

        $february = BillingCycle::Monthly->advance($january, null, 31);
        self::assertSame('2026-02-28', $february->format('Y-m-d'));

        // The anchor day brings it back to the 31st once the month is long
        // enough. Without it, the date would stay on the 28th for ever.
        $march = BillingCycle::Monthly->advance($february, null, 31);
        self::assertSame('2026-03-31', $march->format('Y-m-d'));

        $april = BillingCycle::Monthly->advance($march, null, 31);
        self::assertSame('2026-04-30', $april->format('Y-m-d'));
    }

    public function testLeapYearFebruaryIsHandled(): void
    {
        $january = new DateTimeImmutable('2028-01-31');

        self::assertSame('2028-02-29', BillingCycle::Monthly->advance($january, null, 31)->format('Y-m-d'));
    }

    public function testQuarterlyAndYearlyUseTheSameClamping(): void
    {
        $novemberThirtieth = new DateTimeImmutable('2026-11-30');
        self::assertSame(
            '2027-02-28',
            BillingCycle::Quarterly->advance($novemberThirtieth, null, 30)->format('Y-m-d'),
        );

        $leapDay = new DateTimeImmutable('2028-02-29');
        self::assertSame('2029-02-28', BillingCycle::Yearly->advance($leapDay, null, 29)->format('Y-m-d'));
    }

    public function testDayBasedCyclesJustAddDays(): void
    {
        $date = new DateTimeImmutable('2026-01-31');

        self::assertSame('2026-02-07', BillingCycle::Weekly->advance($date)->format('Y-m-d'));
        self::assertSame('2026-02-14', BillingCycle::CustomDays->advance($date, 14)->format('Y-m-d'));
    }

    public function testOneOffAndLifetimeEntriesHaveNoMonthlyFigure(): void
    {
        // Amortising a lifetime licence over a made-up horizon would make the
        // recurring totals mean something different from what they say.
        foreach ([SubscriptionType::OneOff, SubscriptionType::Lifetime] as $type) {
            $subscription = $this->subscription($type, BillingCycle::Monthly);

            self::assertNull($subscription->monthlyMinor());
            self::assertNull($subscription->yearlyMinor());
            self::assertFalse($type->countsTowardsRecurringTotals());
        }

        $recurring = $this->subscription(SubscriptionType::Recurring, BillingCycle::Monthly);
        self::assertSame(1000, $recurring->monthlyMinor());
        self::assertSame(12000, $recurring->yearlyMinor());
    }

    public function testCancellationDeadlineSubtractsTheNoticePeriod(): void
    {
        $subscription = $this->subscription(
            SubscriptionType::Recurring,
            BillingCycle::Yearly,
            NoticePeriod::of(1, NoticePeriod::UNIT_MONTHS),
            new DateTimeImmutable('2026-03-31'),
        );

        // One calendar month before 31 March, clamped like any other month
        // arithmetic in the application.
        self::assertSame('2026-02-28', $subscription->cancellationDeadline()?->format('Y-m-d'));

        $noNotice = $this->subscription(SubscriptionType::Recurring, BillingCycle::Yearly);
        self::assertNull($noNotice->cancellationDeadline());
    }

    private function subscription(
        SubscriptionType $type,
        BillingCycle $cycle,
        ?NoticePeriod $notice = null,
        ?DateTimeImmutable $nextPayment = null,
    ): Subscription {
        return new Subscription(
            id: 1,
            householdId: 1,
            ownerUserId: 1,
            payerUserId: null,
            name: 'Example',
            notes: null,
            price: Money::of(1000, 'GBP'),
            type: $type,
            billingCycle: $cycle,
            cycleDays: null,
            nextPaymentDate: $nextPayment ?? new DateTimeImmutable('2026-06-01'),
            startDate: null,
            anchorDay: null,
            noticePeriod: $notice ?? NoticePeriod::none(),
            isActive: true,
            logoPath: null,
            categoryId: null,
            categoryName: null,
            ownerName: null,
            payerName: null,
            tags: [],
            createdAt: new DateTimeImmutable('2026-01-01'),
            updatedAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
