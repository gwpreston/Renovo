<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BillingCycle;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\PriceHistoryService;
use App\Service\TrialService;
use App\Support\FrozenClock;
use DateTimeImmutable;

/**
 * Trial-conversion timing.
 *
 * The rule under all of it: the trial's last day is the day the charge falls.
 * Off-by-one here is not a cosmetic problem — it is the difference between
 * warning somebody before they are billed and telling them afterwards.
 */
final class TrialConversionTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private PriceHistoryService $priceHistory;
    private TrialService $trials;
    private FrozenClock $clock;

    private int $alice;
    private int $bob;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->household = $households->create('Shared house', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Viewer);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->clock = FrozenClock::at('2026-09-14 10:00:00');
        $this->priceHistory = new PriceHistoryService(
            new PriceHistoryRepository($this->db),
            $this->subscriptions,
            $this->db,
            $this->clock,
        );
        $this->trials = new TrialService(
            $this->subscriptions,
            $this->priceHistory,
            $this->db,
            $this->clock,
        );
    }

    public function testATrialIsNotConvertedOnItsLastDay(): void
    {
        // The 20th is still free. Converting it on the 20th would charge the
        // user a day early and lose them a day of the trial they were given.
        $id = $this->createTrial(endDate: '2026-09-20', convertsToMinor: 1299);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 09:00:00'));

        self::assertSame(0, $this->trials->convertDueTrials($this->scope()));

        $subscription = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($subscription);
        self::assertTrue($subscription->isTrial);
        self::assertSame(0, $subscription->price->amountMinor);
    }

    public function testATrialConvertsTheDayAfterItEnds(): void
    {
        $id = $this->createTrial(endDate: '2026-09-20', convertsToMinor: 1299);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-21 09:00:00'));

        self::assertSame(1, $this->trials->convertDueTrials($this->scope()));

        $subscription = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($subscription);
        self::assertFalse($subscription->isTrial);
        self::assertSame(1299, $subscription->price->amountMinor);

        // The charge is dated to the trial's last day, which is when it
        // actually happened — not to whenever somebody next opened the app.
        self::assertSame('2026-09-20', $subscription->nextPaymentDate?->format('Y-m-d'));
    }

    public function testConversionIsDatedToTheTrialEndEvenIfNobodyLooksForWeeks(): void
    {
        $id = $this->createTrial(endDate: '2026-09-20', convertsToMinor: 1299);

        // A fortnight of nobody opening the application.
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-04 09:00:00'));
        $this->trials->convertDueTrials($this->scope());

        $history = $this->priceHistory->historyFor($this->scope(), $id);
        $conversion = end($history);

        self::assertNotFalse($conversion);
        self::assertSame(PriceChangeSource::TrialConversion, $conversion->source);
        self::assertSame('2026-09-20', $conversion->effectiveFrom->format('Y-m-d'));
        self::assertSame(1299, $conversion->price->amountMinor);
    }

    public function testConversionAppearsInThePriceHistoryAsAStep(): void
    {
        $id = $this->createTrial(endDate: '2026-09-10', convertsToMinor: 1299);

        $this->trials->convertDueTrials($this->scope());

        $trend = $this->priceHistory->trendFor($this->scope(), $id);

        self::assertCount(2, $trend);
        self::assertSame(0, $trend[0]['change']->price->amountMinor);
        self::assertSame(1299, $trend[1]['change']->price->amountMinor);
        self::assertSame(1299, $trend[1]['difference_minor']);
        self::assertSame(PriceChangeSource::TrialConversion, $trend[1]['change']->source);
    }

    public function testConversionAdoptsTheConvertsToBillingCycle(): void
    {
        // A monthly trial of a yearly plan is common, and getting this wrong
        // would multiply the forecast by twelve in the wrong direction.
        $id = $this->createTrial(
            endDate: '2026-09-10',
            convertsToMinor: 9900,
            convertsToCycle: BillingCycle::Yearly,
        );

        $this->trials->convertDueTrials($this->scope());

        $subscription = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($subscription);
        self::assertSame(BillingCycle::Yearly, $subscription->billingCycle);
        self::assertSame(9900, $subscription->price->amountMinor);
    }

    public function testATrialWithNoConvertsToPriceKeepsThePriceItAlreadyHad(): void
    {
        $id = $this->createTrial(endDate: '2026-09-10', convertsToMinor: null, priceMinor: 799);

        $this->trials->convertDueTrials($this->scope());

        $subscription = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($subscription);
        self::assertFalse($subscription->isTrial);
        self::assertSame(799, $subscription->price->amountMinor);
    }

    public function testConvertingIsIdempotent(): void
    {
        $this->createTrial(endDate: '2026-09-10', convertsToMinor: 1299);

        self::assertSame(1, $this->trials->convertDueTrials($this->scope()));
        self::assertSame(0, $this->trials->convertDueTrials($this->scope()));
        self::assertSame(0, $this->trials->convertDueTrials($this->scope()));
    }

    public function testAnInactiveTrialIsLeftAlone(): void
    {
        // Cancelled during the trial: it never converts, and the whole point of
        // cancelling in time was that it would not.
        $id = $this->createTrial(endDate: '2026-09-10', convertsToMinor: 1299, isActive: false);

        self::assertSame(0, $this->trials->convertDueTrials($this->scope()));
        self::assertTrue($this->subscriptions->find($this->scope(), $id)?->isTrial);
    }

    public function testAViewerConvertsNothing(): void
    {
        $id = $this->createTrial(endDate: '2026-09-10', convertsToMinor: 1299);

        $viewer = Scope::forMember($this->bob, false, $this->household, Role::Viewer, IsolationMode::Shared);

        self::assertSame(0, $this->trials->convertDueTrials($viewer));
        self::assertTrue($this->subscriptions->find($this->scope(), $id)?->isTrial);
    }

    public function testTrialsEndingSoonAreListedInOrderOfUrgency(): void
    {
        $this->createTrial(endDate: '2026-09-25', convertsToMinor: 500, name: 'Later');
        $this->createTrial(endDate: '2026-09-16', convertsToMinor: 1299, name: 'Sooner');
        $this->createTrial(endDate: '2026-12-01', convertsToMinor: 400, name: 'Far off');

        $ending = $this->trials->endingSoon($this->scope(), 30);

        self::assertSame(['Sooner', 'Later'], array_map(
            static fn ($subscription): string => $subscription->name,
            $ending,
        ));
    }

    public function testConversionTotalsUseTheConvertsToPriceNotTheTrialPrice(): void
    {
        // The number people want is what it is about to start costing, which is
        // never the zero it costs today.
        $this->createTrial(endDate: '2026-09-16', convertsToMinor: 1299);
        $this->createTrial(endDate: '2026-09-18', convertsToMinor: 500);

        $totals = $this->trials->conversionTotals($this->trials->endingSoon($this->scope(), 30));

        self::assertSame([['currency' => 'GBP', 'total_minor' => 1799, 'count' => 2]], $totals);
    }

    public function testTrialDayCountsAreMeasuredFromToday(): void
    {
        $id = $this->createTrial(endDate: '2026-09-20', convertsToMinor: 1299);
        $subscription = $this->subscriptions->find($this->scope(), $id);

        self::assertNotNull($subscription);
        self::assertSame(6, $subscription->daysUntilTrialEnds($this->clock->today()));
        self::assertTrue($subscription->isTrialActiveOn($this->clock->today()));
        self::assertTrue($subscription->isTrialActiveOn($this->date('2026-09-20')));
        self::assertFalse($subscription->isTrialActiveOn($this->date('2026-09-21')));
        self::assertTrue($subscription->trialHasEndedBy($this->date('2026-09-21')));
    }

    public function testAnotherHouseholdsTrialIsNeverConverted(): void
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $outsider = $users->create('outsider@example.test', 'Outsider', 'hash');
        $otherHousehold = $households->create('Elsewhere', $outsider);
        $memberships->create($otherHousehold, $outsider, Role::OwnerAdmin);
        $theirScope = Scope::forMember($outsider, false, $otherHousehold, Role::OwnerAdmin, IsolationMode::Shared);

        $theirId = $this->subscriptions->create($theirScope, [
            'name' => 'Their trial',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-09-01',
            'converts_to_price_minor' => 999,
            'is_active' => true,
        ], []);

        self::assertSame(0, $this->trials->convertDueTrials($this->scope()));
        self::assertTrue($this->subscriptions->find($theirScope, $theirId)?->isTrial);
    }

    private function createTrial(
        string $endDate,
        ?int $convertsToMinor,
        int $priceMinor = 0,
        ?BillingCycle $convertsToCycle = null,
        bool $isActive = true,
        string $name = 'Trial',
    ): int {
        $id = $this->subscriptions->create($this->scope(), [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => null,
            'is_trial' => true,
            'trial_end_date' => $endDate,
            'converts_to_price_minor' => $convertsToMinor,
            'converts_to_billing_cycle' => $convertsToCycle?->value,
            'is_active' => $isActive,
        ], []);

        // The service records this on creation; the repository is used directly
        // here, so the same first row is written explicitly to keep the
        // fixture faithful to what the application actually stores.
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            Money::of($priceMinor, 'GBP'),
            $this->date('2026-09-01'),
            $this->alice,
        );

        return $id;
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value . ' 00:00:00');
    }
}
