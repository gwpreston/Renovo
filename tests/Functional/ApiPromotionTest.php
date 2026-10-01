<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Security\Scope;
use App\Service\PriceHistoryService;
use App\Service\SubscriptionService;
use DateTimeImmutable;

/**
 * An intro offer on the subscription resource: read on the list and on one
 * row, and left alone by a PUT, which has no way to describe one.
 */
final class ApiPromotionTest extends ApiTestCase
{
    public function testAnOfferIsReadOnTheListAndOnTheRow(): void
    {
        $ends = (new DateTimeImmutable('+60 days'))->format('Y-m-d');
        $offer = $this->offer($ends);
        $plain = $this->createSubscription(['name' => 'Plain']);

        $row = $this->fetch($offer);
        self::assertTrue($row['price_is_promotional']);
        self::assertSame($ends, $row['promo_ends_on']);
        self::assertSame(1199, $row['promo_then_price_minor']);

        $list = [];
        foreach ($this->decode($this->api('GET', '/api/v1/subscriptions', $this->ownerToken))['data'] as $item) {
            $list[$item['id']] = [$item['price_is_promotional'], $item['promo_ends_on']];
        }
        self::assertSame([true, $ends], $list[$offer]);
        self::assertSame([false, null], $list[$plain]);
    }

    public function testAPutLeavesTheOfferAsItWas(): void
    {
        $ends = (new DateTimeImmutable('+60 days'))->format('Y-m-d');
        $id = $this->offer($ends);

        $representation = $this->fetch($id);
        $representation['name'] = 'Renamed';
        // Read-only: sending it changes nothing either way.
        $representation['price_is_promotional'] = false;

        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $representation);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $after = $this->fetch($id);
        self::assertSame('Renamed', $after['name']);
        self::assertTrue($after['price_is_promotional']);
        self::assertSame($ends, $after['promo_ends_on']);
        self::assertCount(2, $this->container()->get(PriceHistoryService::class)->historyFor($this->owner(), $id));
    }

    private function offer(string $ends): int
    {
        return $this->container()->get(SubscriptionService::class)->create($this->owner(), [
            'name' => 'Spotify',
            'price' => '5.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
            'is_promotional' => '1',
            'offer_ends_on' => $ends,
            'offer_then_price' => '11.99',
        ]);
    }

    private function owner(): Scope
    {
        return Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
