<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionStatus;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Security\SessionInterface;
use App\Service\BudgetService;
use App\Service\BulkActionService;
use App\Service\CatchUpService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\StatsService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Cancelled is not Paused.
 *
 * Both are out of every figure. A paused subscription may resume; a cancelled
 * one is finished — on a trial, cancelling is what stops the conversion — and
 * undoing a cancel lands on Paused, so that correcting a slip cannot quietly
 * restart the charges.
 */
final class CancelledSubscriptionTest extends DatabaseTestCase
{
    private ContainerInterface $container;
    private SubscriptionService $subscriptions;

    private int $owner;
    private int $contributor;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(true, [
            SessionInterface::class => new ArraySession(),
            MailerInterface::class => new RecordingMailer(),
        ]);
        $container = $app->getContainer();
        self::assertNotNull($container);
        $this->container = $container;

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setBaseCurrency('GBP');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $this->owner = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->contributor = $users->create('cora@example.test', 'Cora', 'hash', false, new DateTimeImmutable());
        $this->household = (new HouseholdRepository($this->db))->create('House', $this->owner);
        (new MembershipRepository($this->db))->create($this->household, $this->owner, Role::OwnerAdmin);
        (new MembershipRepository($this->db))->create($this->household, $this->contributor, Role::Contributor);

        $this->subscriptions = $container->get(SubscriptionService::class);
    }

    public function testCancellingATrialStopsItsConversion(): void
    {
        $trial = $this->trialEndingYesterday();

        $this->subscriptions->cancel($this->scope(), $trial);
        $this->container->get(CatchUpService::class)->run($this->scope());

        $row = $this->subscriptions->find($this->scope(), $trial);
        self::assertNotNull($row);
        self::assertTrue($row->isTrial, 'a cancelled trial must never become a paid subscription');
        self::assertSame(0, $row->price->amountMinor);

        $sources = array_map(
            static fn ($change): PriceChangeSource => $change->source,
            $this->container->get(PriceHistoryService::class)->historyFor($this->scope(), $trial),
        );
        self::assertNotContains(PriceChangeSource::TrialConversion, $sources);
    }

    public function testAnUncancelledTrialThatIsResumedConvertsAsNormal(): void
    {
        $trial = $this->trialEndingYesterday();

        $this->subscriptions->cancel($this->scope(), $trial);
        $this->subscriptions->uncancel($this->scope(), $trial);
        $this->subscriptions->setActive($this->scope(), $trial, true);
        $this->container->get(CatchUpService::class)->run($this->scope());

        self::assertFalse($this->subscriptions->find($this->scope(), $trial)?->isTrial);
    }

    public function testCancelSetsTheDateAndSwitchesItOff(): void
    {
        $id = $this->recurring('Streaming', '9.99');

        $this->subscriptions->cancel($this->scope(), $id);

        $row = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($row);
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $row->cancelledAt?->format('Y-m-d'));
        self::assertFalse($row->isActive);
        self::assertSame(SubscriptionStatus::Cancelled, $row->status());
    }

    public function testCancellingTwiceKeepsTheFirstDate(): void
    {
        $id = $this->recurring('Streaming', '9.99');
        $this->db->execute(
            'UPDATE subscriptions SET cancelled_at = :date, is_active = :off WHERE id = :id',
            ['date' => '2026-01-10', 'off' => $this->db->platform()->booleanParameter(false), 'id' => $id],
        );

        $this->subscriptions->cancel($this->scope(), $id);

        self::assertSame('2026-01-10', $this->subscriptions->find($this->scope(), $id)?->cancelledAt?->format('Y-m-d'));
    }

    public function testUndoingACancelLandsOnPausedNotActive(): void
    {
        $id = $this->recurring('Streaming', '9.99');

        $this->subscriptions->cancel($this->scope(), $id);
        $this->subscriptions->uncancel($this->scope(), $id);

        $row = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($row);
        self::assertNull($row->cancelledAt);
        self::assertSame(SubscriptionStatus::Paused, $row->status());
    }

    public function testACancelledSubscriptionCannotBeResumedByAnyRoute(): void
    {
        $id = $this->recurring('Streaming', '9.99');
        $this->subscriptions->cancel($this->scope(), $id);

        try {
            $this->subscriptions->setActive($this->scope(), $id, true);
            self::fail('Resume reached a cancelled subscription.');
        } catch (ValidationException) {
            // Un-cancel first.
        }

        // The form, an API PUT and an import all arrive at update() with an
        // "active" flag. It is not honoured while the row is cancelled.
        $this->subscriptions->update($this->scope(), $id, $this->input('Streaming', '9.99') + ['is_active' => '1']);

        $this->container->get(BulkActionService::class)->apply($this->scope(), [
            'action' => BulkActionService::ACTION_ACTIVATE,
            'ids' => [$id],
        ]);

        $row = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($row);
        self::assertFalse($row->isActive);
        self::assertSame(SubscriptionStatus::Cancelled, $row->status());
    }

    public function testCancelledRowsLeaveTotalsForecastAndBudgets(): void
    {
        $this->recurring('Kept', '10.00');
        $cancelled = $this->recurring('Gone', '30.00');
        $this->container->get(BudgetService::class)->create($this->scope(), [
            'name' => 'Monthly',
            'period' => 'monthly',
            'amount' => '100.00',
            'currency' => 'GBP',
        ]);

        $this->subscriptions->cancel($this->scope(), $cancelled);

        $stats = $this->container->get(StatsService::class)->dashboard($this->scope());
        self::assertSame(1, $stats['active_count']);
        self::assertSame(1000, $stats['combined_monthly']['amount_minor']);

        $charged = array_map(
            static fn (array $charge): int => $charge['subscription']->id,
            $this->container->get(ForecastService::class)->charges($this->scope(), 12),
        );
        self::assertNotContains($cancelled, $charged);

        self::assertNotContains($cancelled, $this->ids($this->subscriptions->upcoming($this->scope(), 60)));

        $budget = $this->container->get(BudgetService::class)->progress($this->scope())[0];
        self::assertSame(1000, $budget['projected']?->amountMinor);
    }

    public function testThePausedFigureIsPausedRowsOnly(): void
    {
        $paused = $this->recurring('Resting', '5.00');
        $cancelled = $this->recurring('Gone', '30.00');
        $this->subscriptions->setActive($this->scope(), $paused, false);
        $this->subscriptions->cancel($this->scope(), $cancelled);

        self::assertSame([$paused], $this->ids($this->subscriptions->paused($this->scope())));
    }

    public function testTheListFindsCancelledRowsUnderTheirOwnFilter(): void
    {
        $active = $this->recurring('Running', '5.00');
        $paused = $this->recurring('Resting', '5.00');
        $cancelled = $this->recurring('Gone', '5.00');
        $trial = $this->trialEndingIn(20);
        $this->subscriptions->setActive($this->scope(), $paused, false);
        $this->subscriptions->cancel($this->scope(), $cancelled);

        $web = static fn (array $query): SubscriptionFilter => SubscriptionFilter::fromQueryParams($query)
            ->withIncludeInactive();

        $listed = fn (SubscriptionFilter $filter): array => $this->ids(
            $this->subscriptions->list($this->scope(), $filter),
        );
        $sorted = static function (array $ids): array {
            sort($ids);

            return $ids;
        };

        self::assertSame($sorted([$active, $paused, $trial]), $sorted($listed($web([]))));
        self::assertSame([$cancelled], $listed($web(['status' => 'cancelled'])));
        self::assertSame([$paused], $listed($web(['status' => 'paused'])));
        self::assertSame([$trial], $listed($web(['status' => 'trial'])));
        self::assertSame([$active], $listed($web(['status' => 'active'])));

        // The API's `inactive=1` still means everything switched off.
        self::assertContains($cancelled, $listed(SubscriptionFilter::fromQueryParams(['inactive' => '1'])));
        self::assertNotContains($cancelled, $listed(SubscriptionFilter::fromQueryParams([])));
    }

    public function testAContributorCannotCancelSomebodyElsesSubscription(): void
    {
        $id = $this->recurring('Owners', '5.00');
        $contributor = Scope::forMember(
            $this->contributor,
            false,
            $this->household,
            Role::Contributor,
            IsolationMode::Shared,
        );

        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->cancel($contributor, $id);
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->owner, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function recurring(string $name, string $price): int
    {
        return $this->subscriptions->create($this->scope(), $this->input($name, $price));
    }

    private function trialEndingYesterday(): int
    {
        return $this->trialEndingIn(-1);
    }

    private function trialEndingIn(int $days): int
    {
        return $this->subscriptions->create($this->scope(), [
            'name' => 'Trial',
            'price' => '0.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => '1',
            'trial_end_date' => (new DateTimeImmutable(sprintf('%+d days', $days)))->format('Y-m-d'),
            'converts_to_price' => '12.99',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function input(string $name, string $price): array
    {
        return [
            'name' => $name,
            'price' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'),
        ];
    }

    /**
     * @param list<\App\Domain\Entity\Subscription> $subscriptions
     * @return list<int>
     */
    private function ids(array $subscriptions): array
    {
        return array_map(static fn ($subscription): int => $subscription->id, $subscriptions);
    }
}
