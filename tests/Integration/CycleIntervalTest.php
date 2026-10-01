<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BillingCycle;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\Scenario;
use App\Http\GuardedClient;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\ForecastService;
use App\Service\SubscriptionFormService;
use App\Service\SubscriptionService;
use App\Service\TrialService;
use App\Service\ValidationException;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\FakeGuardedClient;
use DateTimeImmutable;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

use function DI\factory;

/**
 * Every N weeks, months or years, through the services that store, convert
 * and forecast it.
 */
final class CycleIntervalTest extends DatabaseTestCase
{
    private SubscriptionService $subscriptions;
    private SubscriptionFormService $form;
    private TrialService $trials;
    private ForecastService $forecast;
    private FrozenClock $clock;
    private Scope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-06-01 09:00:00');

        $container = $this->container();
        $this->subscriptions = $container->get(SubscriptionService::class);
        $this->form = $container->get(SubscriptionFormService::class);
        $this->trials = $container->get(TrialService::class);
        $this->forecast = $container->get(ForecastService::class);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $userId = $users->create('owner@example.test', 'Owner', 'hash', false, $this->clock->now());
        $householdId = $households->create('Household', $userId);
        $memberships->create($householdId, $userId, Role::OwnerAdmin);

        $this->scope = Scope::forMember($userId, false, $householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    public function testAnIntervalIsStoredAndReadBack(): void
    {
        $id = $this->create(['billing_cycle' => 'monthly', 'cycle_interval' => '6']);

        $policy = $this->subscriptions->find($this->scope, $id);
        self::assertSame(BillingCycle::Monthly, $policy?->billingCycle);
        self::assertSame(6, $policy->cycleInterval);
        self::assertSame(36000, $policy->yearlyMinor());
    }

    public function testAMissingIntervalIsOne(): void
    {
        $id = $this->create(['billing_cycle' => 'yearly']);

        self::assertSame(1, $this->subscriptions->find($this->scope, $id)?->cycleInterval);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidIntervals(): array
    {
        return [
            'monthly 25' => ['monthly', '25', 'error.cycle_interval.monthly'],
            'weekly 0' => ['weekly', '0', 'error.cycle_interval.weekly'],
            'yearly 11' => ['yearly', '11', 'error.cycle_interval.yearly'],
            'not a number' => ['monthly', 'six', 'error.cycle_interval.monthly'],
            'quarterly 2' => ['quarterly', '2', 'error.cycle_interval.not_allowed'],
            'custom days 2' => ['custom_days', '2', 'error.cycle_interval.not_allowed'],
        ];
    }

    /**
     * @dataProvider invalidIntervals
     */
    public function testAnIntervalOutsideItsBoundsIsAValidationError(string $cycle, string $interval, string $key): void
    {
        try {
            $this->create(['billing_cycle' => $cycle, 'cycle_days' => '30', 'cycle_interval' => $interval]);
            self::fail('The interval was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame($key, $exception->errors()['cycle_interval']->key ?? null);
        }
    }

    public function testTheFormSendsOneForACycleThatTakesNone(): void
    {
        // The hidden "every N" still holds 6 when Quarterly is chosen; the
        // form must not fail on a field nobody can see.
        $id = $this->form->create($this->scope, $this->input([
            'cycle_kind' => 'quarterly',
            'cycle_unit' => 'monthly',
            'cycle_interval' => '6',
        ]));

        $row = $this->subscriptions->find($this->scope, $id);
        self::assertSame(BillingCycle::Quarterly, $row?->billingCycle);
        self::assertSame(1, $row->cycleInterval);

        $id = $this->form->create($this->scope, $this->input([
            'cycle_kind' => 'every',
            'cycle_unit' => 'weekly',
            'cycle_interval' => '2',
        ]));
        $row = $this->subscriptions->find($this->scope, $id);
        self::assertSame([BillingCycle::Weekly, 2], [$row?->billingCycle, $row?->cycleInterval]);
    }

    public function testAnEditThatDoesNotMentionTheIntervalKeepsIt(): void
    {
        $id = $this->create(['billing_cycle' => 'monthly', 'cycle_interval' => '6']);

        $this->subscriptions->update(
            $this->scope,
            $id,
            $this->input(['billing_cycle' => 'monthly', 'name' => 'Renamed']),
        );
        self::assertSame(6, $this->subscriptions->find($this->scope, $id)?->cycleInterval);

        // A change of cycle without one is every one of the new unit.
        $this->subscriptions->update($this->scope, $id, $this->input(['billing_cycle' => 'yearly']));
        self::assertSame(1, $this->subscriptions->find($this->scope, $id)?->cycleInterval);
    }

    public function testTheLastDayOfTheMonthAnchorsToTheThirtyFirst(): void
    {
        $id = $this->create([
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-30',
            'anchor_last_day' => '1',
        ]);
        $row = $this->subscriptions->find($this->scope, $id);
        self::assertSame(BillingCycle::LAST_DAY_ANCHOR, $row?->anchorDay);

        $dates = array_map(
            static fn (array $charge): string => $charge['date']->format('Y-m-d'),
            array_slice($this->forecast->charges($this->scope, 3), 0, 3),
        );
        self::assertSame(['2026-06-30', '2026-07-31', '2026-08-31'], $dates);

        // Without it, the 30th is the anchor.
        $plain = $this->create(['billing_cycle' => 'monthly', 'next_payment_date' => '2026-06-30']);
        self::assertSame(30, $this->subscriptions->find($this->scope, $plain)?->anchorDay);

        // A save that does not mention the option keeps it.
        $this->subscriptions->update($this->scope, $id, $this->input([
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-30',
        ]));
        self::assertSame(31, $this->subscriptions->find($this->scope, $id)?->anchorDay);
    }

    public function testTheLastDayOptionNeedsAMonthEnd(): void
    {
        try {
            $this->create([
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-06-15',
                'anchor_last_day' => '1',
            ]);
            self::fail('A mid-month date was accepted as the last day.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'error.anchor_last_day.not_month_end',
                $exception->errors()['anchor_last_day']->key ?? null,
            );
        }
    }

    public function testATrialConvertingToTwoMonthlyIsChargedOnTheDayThenTwoMonthsLater(): void
    {
        $id = $this->create([
            'billing_cycle' => 'monthly',
            'next_payment_date' => '',
            'is_trial' => '1',
            'trial_end_date' => '2026-06-10',
            'converts_to_price' => '12.00',
            'converts_to_billing_cycle' => 'monthly',
            'converts_to_cycle_interval' => '2',
        ]);

        $charges = $this->forecast->charges($this->scope, 6);
        self::assertSame(
            ['2026-06-10', '2026-08-10', '2026-10-10'],
            array_map(static fn (array $charge): string => $charge['date']->format('Y-m-d'), $charges),
        );

        $this->clock->advanceTo(new DateTimeImmutable('2026-06-11 09:00:00'));
        self::assertSame(1, $this->trials->convertDueTrials($this->scope));

        $converted = $this->subscriptions->find($this->scope, $id);
        self::assertFalse($converted?->isTrial);
        self::assertSame(2, $converted->cycleInterval);
        self::assertNull($converted->convertsToCycleInterval);
        self::assertSame('2026-06-10', $converted->nextPaymentDate?->format('Y-m-d'));
        self::assertSame(
            '2026-08-10',
            $this->subscriptions->nextDueDateFrom($converted, $this->clock->today())?->format('Y-m-d'),
        );
    }

    public function testTheForecastPutsASixMonthlyChargeInTheRightMonthsForTheWholeHorizon(): void
    {
        $this->create([
            'billing_cycle' => 'monthly',
            'cycle_interval' => '6',
            'next_payment_date' => '2026-08-31',
        ]);

        $dates = array_map(
            static fn (array $charge): string => $charge['date']->format('Y-m-d'),
            $this->forecast->charges($this->scope, 36),
        );

        // February and August, every year, on the anchor — never drifting
        // the way 182 days would have by the third year.
        self::assertSame(['2026-08-31', '2027-02-28', '2027-08-31', '2028-02-29', '2028-08-31', '2029-02-28'], $dates);

        $policy = $this->subscriptions->allForStats($this->scope)[0];
        self::assertSame(ForecastService::CADENCE_LONG, $this->forecast->cadenceOf($policy));
    }

    public function testAScenarioChoosingMonthlyForASixMonthlyPolicyMovesItsDates(): void
    {
        $id = $this->create([
            'billing_cycle' => 'monthly',
            'cycle_interval' => '6',
            'price' => '180.00',
            'next_payment_date' => '2026-08-31',
        ]);
        $policy = $this->subscriptions->find($this->scope, $id);
        self::assertNotNull($policy);

        $dates = static fn (array $charges): array => array_map(
            static fn (array $charge): string => $charge['date']->format('Y-m-d'),
            $charges,
        );

        // "Same cycle" keeps every six months, at the new price.
        $same = Scenario::fromQuery(['change' => [$id => ['price' => '150.00', 'cycle' => '']]], [$id => $policy]);
        self::assertSame(['2026-08-31', '2027-02-28'], $dates($this->forecast->charges($this->scope, 12, null, $same)));

        // "Monthly" is every month.
        $monthly = Scenario::fromQuery(
            ['change' => [$id => ['price' => '30.00', 'cycle' => 'monthly']]],
            [$id => $policy],
        );
        self::assertCount(10, $this->forecast->charges($this->scope, 12, null, $monthly));
    }

    public function testEveryFourWeeksIsStillRegular(): void
    {
        $this->create(['billing_cycle' => 'weekly', 'cycle_interval' => '4']);
        $this->create(['billing_cycle' => 'weekly', 'cycle_interval' => '5', 'name' => 'Five']);

        $cadences = [];
        foreach ($this->subscriptions->allForStats($this->scope) as $subscription) {
            $cadences[$subscription->name] = $this->forecast->cadenceOf($subscription);
        }

        self::assertSame(ForecastService::CADENCE_REGULAR, $cadences['Example']);
        self::assertSame(ForecastService::CADENCE_LONG, $cadences['Five']);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function create(array $overrides): int
    {
        return $this->subscriptions->create($this->scope, $this->input($overrides));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides): array
    {
        return $overrides + [
            'name' => 'Example',
            'price' => '180.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'next_payment_date' => '2026-06-15',
        ];
    }

    private function container(): ContainerInterface
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['database'] = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);
        $builder->addDefinitions([
            Clock::class => $this->clock,
            GuardedClient::class => factory(fn (): GuardedClient => FakeGuardedClient::returning('', 404)),
        ]);

        return $builder->build();
    }
}
