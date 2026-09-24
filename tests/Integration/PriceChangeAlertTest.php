<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AlertType;
use App\Domain\DigestMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Visibility;
use App\Repository\PriceHistoryRepository;
use App\Service\PriceHistoryService;
use App\Service\TrialService;
use DateTimeImmutable;

/**
 * The price-change alert: once per genuine change, and never for a price that
 * merely arrived, a trial ending or an amount re-denominated.
 *
 * Alice owns everything here; Bob is a second member of the same SHARED
 * household, which is what makes "whoever can see it" a different set of people
 * from "whoever owns it".
 */
final class PriceChangeAlertTest extends NotificationTestCase
{
    private PriceHistoryService $prices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prices = new PriceHistoryService(
            new PriceHistoryRepository($this->db),
            $this->subscriptions,
            $this->db,
            $this->clock,
        );
    }

    public function testAManualPriceChangeIsAnnouncedExactlyOnce(): void
    {
        $this->addChannel($this->alice);
        $id = $this->subscriptionWithHistory('Netflix', 1099);

        $this->changePrice($id, 1299);

        $this->runner->run();
        self::assertSame(1, $this->priceAlertCount());

        $alert = $this->priceAlerts()[0];
        self::assertStringContainsString('Netflix', $alert->title);
        self::assertStringContainsString('£10.99', $alert->lines[0]);
        self::assertStringContainsString('£12.99', $alert->lines[0]);
        // Two pounds a month is twenty-four a year.
        self::assertStringContainsString('£24.00', $alert->lines[1]);

        // The history row is the occurrence: another run, and another day,
        // are both silent.
        $this->runner->run();
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-16 08:00:00'));
        $this->runner->run();
        self::assertSame(1, $this->priceAlertCount());
    }

    public function testAFirstPriceIsNotAChange(): void
    {
        $this->addChannel($this->alice);
        $this->subscriptionWithHistory('Netflix', 1099);

        $this->runner->run();

        self::assertSame(0, $this->priceAlertCount());
    }

    public function testATrialConversionIsNotAPriceChange(): void
    {
        $this->addChannel($this->alice);
        $id = $this->subscriptionWithHistory('Trial thing', 0, [
            'is_trial' => true,
            'trial_end_date' => '2026-09-14',
            'converts_to_price_minor' => 999,
            'next_payment_date' => null,
        ]);

        $converted = (new TrialService($this->subscriptions, $this->prices, $this->db, $this->clock))
            ->convertDueTrials($this->scope($this->alice));
        self::assertSame(1, $converted);

        $this->runner->run();

        self::assertSame(0, $this->priceAlertCount());
    }

    public function testACurrencyConversionIsNotAPriceChange(): void
    {
        $this->addChannel($this->alice);
        $id = $this->subscriptionWithHistory('Netflix', 1099);

        $this->prices->recordCurrentPrice(
            $this->scope($this->alice),
            $id,
            Money::of(1319, 'EUR'),
            PriceChangeSource::CurrencyChange,
            $this->alice,
        );

        $this->runner->run();

        self::assertSame(0, $this->priceAlertCount());
    }

    public function testAScheduledRiseIsAnnouncedWhenScheduledAndNotAgainWhenItTakesEffect(): void
    {
        $this->addChannel($this->alice);
        $id = $this->subscriptionWithHistory('Spotify', 1199);

        $this->prices->schedule($this->scope($this->alice), $id, [
            'price' => '12.99',
            'effective_from' => '2026-10-01',
        ]);

        $this->runner->run();
        self::assertSame(1, $this->priceAlertCount());
        self::assertStringContainsString('1 Oct 2026', $this->priceAlerts()[0]->lines[0]);

        // The date arrives; the catch-up applies the price. No new row, so no
        // new alert.
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-02 08:00:00'));
        $this->runner->run();

        self::assertSame(1, $this->priceAlertCount());
        self::assertSame(1299, $this->subscriptions->find($this->scope($this->alice), $id)?->price->amountMinor);
    }

    public function testEveryoneWhoCanSeeTheSubscriptionIsTold(): void
    {
        $this->addChannel($this->alice);
        $this->addChannel($this->bob);
        $id = $this->subscriptionWithHistory('Broadband', 3000);

        $this->changePrice($id, 3500);
        $this->runner->run();

        self::assertSame([$this->alice, $this->bob], $this->priceAlertRecipients());
    }

    public function testAPrivateSubscriptionsChangeReachesOnlyItsPayer(): void
    {
        $this->addChannel($this->alice);
        $this->addChannel($this->bob);
        $id = $this->subscriptionWithHistory('Therapy', 5000, [
            'visibility' => Visibility::Payer->value,
        ]);

        $this->changePrice($id, 5500);
        $this->runner->run();

        self::assertSame([$this->alice], $this->priceAlertRecipients());
    }

    public function testTurningTheAlertOffSilencesIt(): void
    {
        $this->addChannel($this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Immediate->value,
            'price_change_alerts' => '0',
        ]);
        $id = $this->subscriptionWithHistory('Netflix', 1099);

        $this->changePrice($id, 1299);
        $this->runner->run();

        self::assertSame(0, $this->priceAlertCount());
        self::assertFalse($this->notificationSettings->preferences($this->alice)->priceChangeAlerts);
    }

    public function testAPreferencesSaveThatDoesNotMentionTheToggleKeepsIt(): void
    {
        $this->notificationSettings->savePreferences($this->alice, ['lead_days' => '7', 'price_change_alerts' => '0']);
        $this->notificationSettings->savePreferences($this->alice, ['lead_days' => '30']);

        self::assertFalse($this->notificationSettings->preferences($this->alice)->priceChangeAlerts);
    }

    public function testRoutingIsHonoured(): void
    {
        $phone = $this->addChannel($this->alice, 'Phone');
        $email = $this->addChannel($this->alice, 'Email');
        $this->notificationSettings->saveRoutes($this->alice, [
            $phone => [AlertType::Renewal->value, AlertType::PriceChange->value],
            $email => [AlertType::Renewal->value],
        ]);
        $id = $this->subscriptionWithHistory('Netflix', 1099);

        $this->changePrice($id, 1299);
        $this->runner->run();

        $channels = [];
        foreach ($this->notifier->sent as $sent) {
            if ($sent['alert']->type === AlertType::PriceChange) {
                $channels[] = $sent['channel']->id;
            }
        }
        self::assertSame([$phone], $channels);
    }

    public function testADigestCarriesAPriceChangesSectionAndDoesNotRepeatIt(): void
    {
        $this->addChannel($this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Weekly->value,
            'digest_day' => '1',
        ]);
        $id = $this->subscriptionWithHistory('Netflix', 1099);
        $this->changePrice($id, 1299);
        $this->stampHistory('2026-09-16 10:00:00');

        // Monday 21 September: the digest, with the change in its own section.
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-21 08:00:00'));
        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
        $digest = $this->notifier->sent[0]['alert'];
        self::assertContains('Price change:', array_map('trim', $digest->lines));
        self::assertStringContainsString('Netflix', $digest->body());

        // A second change, recorded the same Monday after the digest went.
        $this->changePrice($id, 1399);
        $this->db->execute(
            'UPDATE subscription_price_history SET created_at = :at WHERE price_minor = 1399',
            ['at' => '2026-09-21 10:00:00'],
        );

        // The next Monday: the second change is there, once, and the first is
        // not there again. The window is "since the last digest", not "the
        // last seven days" — which would have lost nothing here but repeats
        // whenever a period is shorter than its horizon.
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-28 08:00:00'));
        $this->runner->run();

        $second = end($this->notifier->sent);
        self::assertNotFalse($second);
        $body = $second['alert']->body();
        self::assertSame(1, substr_count($body, '£12.99 → £13.99'));
        self::assertStringNotContainsString('£10.99 → £12.99', $body);
    }

    private function changePrice(int $subscriptionId, int $priceMinor): void
    {
        $this->prices->recordCurrentPrice(
            $this->scope($this->alice),
            $subscriptionId,
            Money::of($priceMinor, 'GBP'),
            PriceChangeSource::Manual,
            $this->alice,
        );
    }

    public function testAFailedDigestDoesNotSwallowTheChangesItWasCarrying(): void
    {
        $this->addChannel($this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Weekly->value,
            'digest_day' => '1',
        ]);
        // Renewing within the first digest's week, so that digest has
        // something to say and is delivered.
        $id = $this->subscriptionWithHistory('Netflix', 1099, ['next_payment_date' => '2026-09-25']);

        // 21 September: a digest goes out.
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-21 08:00:00'));
        $this->runner->run();
        self::assertCount(1, $this->notifier->sent);

        // A change on the Tuesday; the next Monday's digest fails.
        $this->changePrice($id, 1299);
        $this->stampHistory('2026-09-22 10:00:00');
        $this->notifier->failNext();
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-28 08:00:00'));
        $this->runner->run();
        $this->notifier->failNext(false);

        // The one after still carries it: the window runs from the last
        // digest that was delivered, not the last one attempted.
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $this->runner->run();

        $last = end($this->notifier->sent);
        self::assertNotFalse($last);
        self::assertStringContainsString('£10.99 → £12.99', $last['alert']->body());
    }

    /**
     * A subscription with the first row of its history, as the form would
     * write it.
     *
     * @param array<string, mixed> $extra
     */
    private function subscriptionWithHistory(string $name, int $priceMinor, array $extra = []): int
    {
        $next = array_key_exists('next_payment_date', $extra) ? $extra['next_payment_date'] : '2026-10-05';
        $id = $this->createSubscription($name, $priceMinor, is_string($next) ? $next : null, $this->alice, $extra);

        $this->prices->recordInitialPrice(
            $this->scope($this->alice),
            $id,
            Money::of($priceMinor, 'GBP'),
            new DateTimeImmutable('2026-01-01'),
            $this->alice,
        );

        return $id;
    }

    /**
     * History rows are stamped by the database clock rather than the frozen
     * one; a digest test needs them on the frozen timeline.
     */
    private function stampHistory(string $at): void
    {
        $this->db->execute(
            'UPDATE subscription_price_history SET created_at = :at WHERE source <> :initial',
            ['at' => $at, 'initial' => PriceChangeSource::Initial->value],
        );
    }

    private function priceAlertCount(): int
    {
        return count($this->priceAlerts());
    }

    /**
     * @return list<\App\Notification\Alert>
     */
    private function priceAlerts(): array
    {
        $alerts = [];
        foreach ($this->notifier->sent as $sent) {
            if ($sent['alert']->type === AlertType::PriceChange) {
                $alerts[] = $sent['alert'];
            }
        }

        return $alerts;
    }

    /**
     * @return list<int>
     */
    private function priceAlertRecipients(): array
    {
        $users = [];
        foreach ($this->notifier->sent as $sent) {
            if ($sent['alert']->type === AlertType::PriceChange) {
                $users[] = $sent['user']->id;
            }
        }
        sort($users);

        return $users;
    }
}
