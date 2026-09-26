<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The four Phase 20 fields through the API: `plan` and `visibility` written
 * like the others, `cancelled_at` and `status` read-only and moved by their own
 * endpoints.
 */
final class ApiPhaseTwentyTest extends ApiTestCase
{
    public function testPlanRoundTripsAndAnAbsentKeyKeepsIt(): void
    {
        $id = $this->createSubscription(['plan' => 'Family']);

        $shown = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
        self::assertSame('Family', $shown['plan']);

        unset($shown['plan']);
        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $shown);
        self::assertSame('Family', $this->show($id)['plan']);

        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, ['plan' => null] + $shown);
        self::assertNull($this->show($id)['plan']);
    }

    public function testAPrivateSubscriptionIsANotFoundToEveryOtherToken(): void
    {
        $id = $this->createSubscription(['name' => 'Private', 'visibility' => 'payer'], $this->editorToken);

        $own = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->editorToken));
        self::assertSame('payer', $own['data']['visibility']);

        foreach ([$this->ownerToken, $this->viewerToken] as $token) {
            self::assertSame(404, $this->api('GET', '/api/v1/subscriptions/' . $id, $token)->getStatusCode());
            $listed = array_column(
                $this->decode($this->api('GET', '/api/v1/subscriptions?inactive=1', $token))['data'],
                'id',
            );
            self::assertNotContains($id, $listed);
        }

        // The Owner/Admin's write reaches nothing either.
        self::assertSame(404, $this->api('DELETE', '/api/v1/subscriptions/' . $id, $this->ownerToken)->getStatusCode());
        $cancel = $this->api('POST', '/api/v1/subscriptions/' . $id . '/cancel', $this->ownerToken);
        self::assertSame(404, $cancel->getStatusCode());
    }

    public function testAPutThatOmitsVisibilityKeepsASubscriptionPrivate(): void
    {
        $id = $this->createSubscription(['visibility' => 'payer'], $this->editorToken);

        $shown = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->editorToken))['data'];
        unset($shown['visibility']);
        $shown['name'] = 'Renamed';

        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->editorToken, $shown);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('payer', $this->decode($response)['data']['visibility']);
    }

    public function testMarkingAnotherMembersSubscriptionPrivateIsAValidationError(): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
            'name' => 'For the editor',
            'price_minor' => 500,
            'currency' => 'GBP',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'owner_user_id' => $this->editorId,
            'visibility' => 'payer',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testCancelAndUncancel(): void
    {
        $id = $this->createSubscription();

        $path = '/api/v1/subscriptions/' . $id;
        $cancelled = $this->decode($this->api('POST', $path . '/cancel', $this->editorToken))['data'];
        self::assertSame('cancelled', $cancelled['status']);
        self::assertNotNull($cancelled['cancelled_at']);
        self::assertFalse($cancelled['is_active']);

        // Read-only in the payload: a PUT cannot clear it, nor switch it back on.
        $cancelled['cancelled_at'] = null;
        $cancelled['is_active'] = true;
        $put = $this->decode($this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $cancelled))['data'];
        self::assertSame('cancelled', $put['status']);

        $filtered = array_column(
            $this->decode($this->api('GET', '/api/v1/subscriptions?status=cancelled', $this->ownerToken))['data'],
            'id',
        );
        self::assertSame([$id], $filtered);

        $undone = $this->decode($this->api('POST', $path . '/uncancel', $this->ownerToken))['data'];
        self::assertSame('paused', $undone['status']);
        self::assertNull($undone['cancelled_at']);
    }

    public function testAViewerCannotCancel(): void
    {
        $id = $this->createSubscription();

        $refused = $this->api('POST', '/api/v1/subscriptions/' . $id . '/cancel', $this->viewerToken);
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('active', $this->show($id)['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
