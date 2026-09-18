<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\NoticePeriod;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Domain\WeekStart;
use App\Repository\CategoryRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\CalendarService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRateService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Support\FrozenClock;
use App\Tests\Support\TestLogoFetcher;
use DateTimeImmutable;
use Psr\Log\NullLogger;

/**
 * The three selections the calendar's rail is built from.
 *
 * They are asserted here rather than by reading the rendered page because each
 * one is a rule about *which* rows belong in it, and a rule is what quietly
 * goes wrong: a deadline measured from the wrong charge, a preview that starts
 * following the month on screen, a trial counted in a month it does not
 * convert in. None of those changes the markup enough to fail a template test.
 *
 * The clock is frozen mid-January so that "this month" and "the next few
 * charges" are different sets — a fixture where they coincide would pass
 * whichever way round the code had them.
 */
final class CalendarRailTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private SplitService $splitService;
    private CalendarService $calendar;
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
        $memberships->create($this->household, $this->bob, Role::Editor);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->clock = FrozenClock::at('2026-01-15 09:00:00');

        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db));
        $settings->setBaseCurrency('GBP');

        $historyRepository = new PriceHistoryRepository($this->db);
        $priceHistory = new PriceHistoryService(
            $historyRepository,
            $this->subscriptions,
            $this->db,
            $this->clock,
        );

        $subscriptionService = new SubscriptionService(
            $this->subscriptions,
            new CategoryRepository($this->db),
            new TagRepository($this->db),
            TestLogoFetcher::silent($this->db, $this->clock),
            $memberships,
            $priceHistory,
            $this->db,
            $this->clock,
        );

        $this->splitService = new SplitService(
            new SplitRepository($this->db),
            $this->subscriptions,
            $memberships,
            $this->db,
        );

        $rates = new ExchangeRateService(
            new ExchangeRateRepository($this->db),
            new ExchangeRateProviderRegistry([]),
            $settings,
            $this->clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );

        $this->calendar = new CalendarService(
            new ForecastService(
                $subscriptionService,
                $historyRepository,
                $this->splitService,
                $rates,
                $settings,
                $this->clock,
            ),
            $this->clock,
        );
    }

    public function testTrialsAreTheMonthsConversionsAndNotItsRenewals(): void
    {
        $this->createTrial('Trial plan', trialEnd: '2026-01-20', convertsToMinor: 1299);
        $this->createSubscription('Streaming', 1000, 'monthly', '2026-01-22');

        $month = $this->month('2026-01');

        self::assertCount(1, $month['trials']);
        self::assertSame('Trial plan', $month['trials'][0]['subscription']->name);
        self::assertSame('2026-01-20', $month['trials'][0]['date']->format('Y-m-d'));
        self::assertSame(['GBP' => 1299], $month['trial_totals']);
    }

    public function testATrialsOwnRenewalsAreNotCountedAsFurtherConversions(): void
    {
        // The trap: after it converts the same subscription keeps charging, and
        // every one of those is a renewal. Counting February's as a conversion
        // would say a free trial ended twice.
        $this->createTrial('Trial plan', trialEnd: '2026-01-20', convertsToMinor: 1299);

        self::assertCount(1, $this->month('2026-01')['trials']);
        self::assertSame([], $this->month('2026-02')['trials']);
        self::assertSame(['GBP' => 1299], $this->month('2026-02')['totals']);
    }

    public function testTrialTotalsStayInTheirOwnCurrencies(): void
    {
        $this->createTrial('Sterling trial', trialEnd: '2026-01-20', convertsToMinor: 1299);
        $this->createTrial('Euro trial', trialEnd: '2026-01-21', convertsToMinor: 900, currency: 'EUR');

        // Two figures, never one: this instance holds no rate, and adding them
        // would invent one.
        self::assertSame(['EUR' => 900, 'GBP' => 1299], $this->month('2026-01')['trial_totals']);
    }

    public function testADeadlineIsMeasuredFromTheNextChargeAndLandsInItsOwnMonth(): void
    {
        // Thirty days' notice on a charge due 20 February puts the deadline in
        // January — a different month from the charge, which is the entire
        // reason the deadline is worth showing separately.
        $this->createSubscription('Gym', 4000, 'monthly', '2026-02-20', notice: 30);

        $january = $this->month('2026-01');

        self::assertCount(1, $january['deadlines']);
        self::assertSame('2026-01-21', $january['deadlines'][0]['date']->format('Y-m-d'));
        self::assertSame('2026-02-20', $january['deadlines'][0]['charge_date']->format('Y-m-d'));
        self::assertSame(4000, $january['deadlines'][0]['amount']->amountMinor);

        // And it is not repeated against the month the charge falls in.
        self::assertSame([], $this->month('2026-02')['deadlines']);
    }

    public function testADeadlineIsMeasuredFromATrialsConversionRatherThanAStalePaymentDate(): void
    {
        // A trial's `next_payment_date` column is either null or a leftover.
        // Measuring from it would tell somebody their deadline had passed when
        // they still had a fortnight — the most damaging thing this card could
        // do.
        $this->createTrial('Trial plan', trialEnd: '2026-02-10', convertsToMinor: 1299, notice: 14);

        $january = $this->month('2026-01');

        self::assertCount(1, $january['deadlines']);
        self::assertSame('2026-01-27', $january['deadlines'][0]['date']->format('Y-m-d'));
    }

    public function testADeadlineThatHasAlreadyPassedIsNotShown(): void
    {
        // Seven days' notice on a charge due on the 18th: the deadline was the
        // 11th and today is the 15th. Nothing can be done about it, so stating
        // it as this month's deadline would be an instruction nobody can follow.
        $this->createSubscription('Missed it', 1000, 'monthly', '2026-01-18', notice: 7);

        self::assertSame([], $this->month('2026-01')['deadlines']);
    }

    public function testASubscriptionWithNoNoticePeriodHasNoDeadline(): void
    {
        $this->createSubscription('Cancel any time', 1000, 'monthly', '2026-02-20');

        self::assertSame([], $this->month('2026-01')['deadlines']);
        self::assertSame([], $this->month('2026-02')['deadlines']);
    }

    public function testTheUpcomingPreviewIsAnchoredToTodayRatherThanToTheMonthOnScreen(): void
    {
        $this->createSubscription('Streaming', 1000, 'monthly', '2026-01-22');

        $january = $this->month('2026-01');
        $june = $this->month('2026-06');

        // Paging the grid to June asks a different question of the grid and the
        // same one of the preview. If this ever starts following the month, the
        // card is answering "what does June cost" under a heading that says
        // "next up".
        self::assertSame(
            $this->dates($january['upcoming']),
            $this->dates($june['upcoming']),
        );
        self::assertSame('2026-01-22', $january['upcoming'][0]['date']->format('Y-m-d'));
        self::assertSame([], $june['weeks'][0][0]['events']);
    }

    public function testTheUpcomingPreviewIsInDateOrderAndCappedAtItsLimit(): void
    {
        // Nine charges inside a fortnight, created back to front so that
        // insertion order cannot be what the assertion is reading.
        foreach ([9, 8, 7, 6, 5, 4, 3, 2, 1] as $offset) {
            $this->createSubscription(
                'Sub ' . $offset,
                100 * $offset,
                'monthly',
                (new DateTimeImmutable('2026-01-16'))->modify('+' . $offset . ' days')->format('Y-m-d'),
            );
        }

        $upcoming = $this->month('2026-01')['upcoming'];

        self::assertCount(CalendarService::UPCOMING_PREVIEW, $upcoming);
        self::assertSame(
            ['2026-01-17', '2026-01-18', '2026-01-19', '2026-01-20', '2026-01-21', '2026-01-22'],
            $this->dates($upcoming),
        );
    }

    public function testTheRailShowsOnlyThisMembersShareWhenScopedToThem(): void
    {
        // The rail is a selection over the same charges the grid is drawn from,
        // so the "just mine" toggle has to reach it without the cards knowing
        // anything about splits.
        $id = $this->createSubscription('Family plan', 1500, 'monthly', '2026-02-20', notice: 30);
        $this->splitService->update($this->scope(), $id, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->alice => 1, $this->bob => 1],
        ]);

        $household = $this->month('2026-01');
        $alice = $this->month('2026-01', $this->alice);

        self::assertSame(1500, $household['deadlines'][0]['amount']->amountMinor);
        self::assertSame(750, $alice['deadlines'][0]['amount']->amountMinor);
    }

    public function testAMemberWhoBearsNoneOfASplitSeesNeitherItsDeadlineNorItsCharge(): void
    {
        $id = $this->createSubscription('Bobs plan', 1000, 'monthly', '2026-02-20', notice: 30);
        $this->splitService->update($this->scope(), $id, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->bob => 1],
        ]);

        $alice = $this->month('2026-01', $this->alice);

        self::assertSame([], $alice['deadlines']);
        self::assertSame([], $alice['upcoming']);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<string>
     */
    private function dates(array $events): array
    {
        return array_map(
            static fn (array $event): string => $event['date']->format('Y-m-d'),
            $events,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function month(string $month, ?int $forUserId = null): array
    {
        return $this->calendar->month(
            $this->scope(),
            new DateTimeImmutable($month . '-01 00:00:00'),
            WeekStart::Monday,
            $forUserId,
        );
    }

    private function createSubscription(
        string $name,
        int $priceMinor,
        ?string $cycle,
        string $nextPayment,
        string $currency = 'GBP',
        ?int $notice = null,
    ): int {
        return $this->subscriptions->create($this->scope(), array_filter([
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => $cycle,
            'next_payment_date' => $nextPayment,
            'anchor_day' => (int) (new DateTimeImmutable($nextPayment))->format('j'),
            'notice_period_amount' => $notice,
            'notice_period_unit' => $notice === null ? null : NoticePeriod::UNIT_DAYS,
            'is_active' => true,
        ], static fn (mixed $value): bool => $value !== null), []);
    }

    private function createTrial(
        string $name,
        string $trialEnd,
        int $convertsToMinor,
        string $currency = 'GBP',
        ?int $notice = null,
    ): int {
        return $this->subscriptions->create($this->scope(), array_filter([
            'name' => $name,
            'price_minor' => 0,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => $trialEnd,
            'converts_to_price_minor' => $convertsToMinor,
            'notice_period_amount' => $notice,
            'notice_period_unit' => $notice === null ? null : NoticePeriod::UNIT_DAYS,
            'is_active' => true,
        ], static fn (mixed $value): bool => $value !== null), []);
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
