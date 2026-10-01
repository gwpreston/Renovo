<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\BillingCycle;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\Scenario;
use App\Tests\Support\SubscriptionFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Reading a scenario from the query string, and the notice rule it is
 * worked out by.
 */
final class ScenarioTest extends TestCase
{
    public function testTheShortFormIsReadAndWrittenBackTheSameWay(): void
    {
        $scenario = Scenario::fromQuery([
            'cancel' => ['12'],
            'change' => ['18' => ['price' => '239.88', 'cycle' => 'yearly']],
        ], $this->eligible(12, 18));

        self::assertTrue($scenario->cancels(12));
        self::assertSame(23988, $scenario->changeFor(18)?->price->amountMinor);
        self::assertSame(BillingCycle::Yearly, $scenario->changeFor(18)->cycle);
        self::assertSame([12, 18], $scenario->ids());
        self::assertSame('cancel[]=12&change[18][price]=239.88&change[18][cycle]=yearly', $scenario->queryString());
        self::assertSame(
            'cancel[]=12&change[18][price]=239.88&change[18][cycle]=yearly&mine=1',
            $scenario->queryString(['mine' => '1']),
        );
    }

    public function testAnIdThatIsNotEligibleOrNotANumberIsDroppedSilently(): void
    {
        $scenario = Scenario::fromQuery([
            'cancel' => ['12', '99', 'x', ['nested'], '-3'],
            'change' => ['99' => ['price' => '1.00'], '12' => 'not an array'],
        ], $this->eligible(12));

        self::assertSame([12], $scenario->ids());
        self::assertFalse($scenario->hasErrors());
    }

    public function testParametersOfTheWrongShapeAreIgnored(): void
    {
        $scenario = Scenario::fromQuery(['cancel' => '12', 'change' => 'x', 'choice' => 'y'], $this->eligible(12));

        self::assertTrue($scenario->isEmpty());
    }

    public function testTheFormsChoiceDecidesWhatARowIs(): void
    {
        $scenario = Scenario::fromQuery([
            'choice' => ['12' => 'keep', '18' => 'cancel', '20' => 'change', '21' => 'nonsense'],
            'cancel' => ['12'],
            'change' => [
                '18' => ['price' => '5.00'],
                '20' => ['price' => '7.50'],
                '21' => ['price' => '1.00'],
            ],
        ], $this->eligible(12, 18, 20, 21));

        self::assertSame(Scenario::KEEP, $scenario->choiceFor(12));
        self::assertSame(Scenario::CANCEL, $scenario->choiceFor(18));
        self::assertNull($scenario->changeFor(18));
        self::assertSame(750, $scenario->changeFor(20)?->price->amountMinor);
        // No valid choice: an amount typed in is a change.
        self::assertSame(Scenario::CHANGE, $scenario->choiceFor(21));
    }

    public function testCancelWinsWhenARowIsNamedForBoth(): void
    {
        $scenario = Scenario::fromQuery([
            'cancel' => ['12'],
            'change' => ['12' => ['price' => '5.00']],
        ], $this->eligible(12));

        self::assertTrue($scenario->cancels(12));
        self::assertNull($scenario->changeFor(12));
    }

    public function testAPriceIsValidatedAsTheFormValidatesOneAndAMistakeCountsAsKeep(): void
    {
        $scenario = Scenario::fromQuery(['change' => [
            '12' => ['price' => 'lots'],
            '18' => ['price' => '-1'],
            '20' => ['price' => '3.00', 'cycle' => 'fortnightly'],
            '21' => ['price' => '3.00', 'cycle' => 'custom_days', 'days' => '0'],
            '22' => ['price' => '3.00', 'cycle' => 'custom_days', 'days' => '45'],
        ]], $this->eligible(12, 18, 20, 21, 22));

        self::assertSame(['price' => 'error.price.invalid'], $scenario->errorsFor(12));
        self::assertSame(['price' => 'error.price.negative'], $scenario->errorsFor(18));
        self::assertSame(['cycle' => 'error.cycle.required'], $scenario->errorsFor(20));
        self::assertSame(['days' => 'error.cycle_days.range'], $scenario->errorsFor(21));
        self::assertSame(45, $scenario->changeFor(22)?->cycleDays);

        // Shown as a change with its error, worked out as Keep, and never
        // written into a bookmark.
        self::assertSame(Scenario::CHANGE, $scenario->choiceFor(12));
        self::assertSame('lots', $scenario->inputFor(12)['price']);
        self::assertSame([22], $scenario->ids());
        self::assertSame(
            'change[22][price]=3.00&change[22][cycle]=custom_days&change[22][days]=45',
            $scenario->queryString(),
        );
    }

    public function testAPriceIsInTheSubscriptionsOwnCurrency(): void
    {
        $scenario = Scenario::fromQuery(
            ['change' => ['5' => ['price' => '1000']]],
            [5 => SubscriptionFactory::make(id: 5, currency: 'JPY')],
        );

        self::assertSame('JPY', $scenario->changeFor(5)?->price->currency);
        self::assertSame(1000, $scenario->changeFor(5)->price->amountMinor);
    }

    public function testAChargeIsAvoidableUntilItsNoticeDeadlinePasses(): void
    {
        $today = new DateTimeImmutable('2026-01-15');
        $monthsNotice = SubscriptionFactory::make(noticePeriod: NoticePeriod::of(1, NoticePeriod::UNIT_MONTHS));

        self::assertFalse($monthsNotice->isChargeAvoidable(new DateTimeImmutable('2026-01-25'), $today));
        // The deadline is today: still in time.
        self::assertTrue($monthsNotice->isChargeAvoidable(new DateTimeImmutable('2026-02-15'), $today));
        self::assertTrue($monthsNotice->isChargeAvoidable(new DateTimeImmutable('2026-02-25'), $today));

        // With no notice period, any charge still to come can be avoided.
        self::assertTrue(SubscriptionFactory::make()->isChargeAvoidable($today, $today));
    }

    public function testNewTermsAreACopyFromTheNextCharge(): void
    {
        $from = new DateTimeImmutable('2026-02-03');
        $monthly = SubscriptionFactory::make(priceMinor: 5499, nextPaymentDate: $from, anchorDay: 3);

        $yearly = $monthly->withTerms(Money::of(23988, 'GBP'), BillingCycle::Yearly, null, $from);
        self::assertSame(1999, $yearly->monthlyMinor());
        self::assertSame(5499, $monthly->monthlyMinor());
        self::assertSame($from, $yearly->nextPaymentDate);

        $trial = SubscriptionFactory::make(
            priceMinor: 0,
            isTrial: true,
            trialEndDate: new DateTimeImmutable('2026-04-10'),
            convertsToPriceMinor: 5000,
            convertsToBillingCycle: BillingCycle::Yearly,
        );
        $changed = $trial->withTerms(Money::of(500, 'GBP'), BillingCycle::Monthly, null, $from);
        self::assertTrue($changed->isTrial);
        self::assertSame(500, $changed->priceAfterConversion()->amountMinor);
        self::assertSame(BillingCycle::Monthly, $changed->billingCycleAfterConversion());
        self::assertSame(0, $changed->price->amountMinor);
    }

    /**
     * @return array<int, \App\Domain\Entity\Subscription>
     */
    private function eligible(int ...$ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = SubscriptionFactory::make(id: $id);
        }

        return $rows;
    }
}
