<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\DiscordNotifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use App\Tests\Support\FakeGuardedClient;

final class DiscordNotifierTest extends NotifierTestCase
{
    private const WEBHOOK = 'https://discord.com/api/webhooks/123456/abcdefTOKEN';

    private function notifierWith(FakeGuardedClient $http): DiscordNotifier
    {
        return $this->notifier(DiscordNotifier::class, $http);
    }

    public function testItPostsTheAlertAsContentOverHttpsOnly(): void
    {
        $http = FakeGuardedClient::returning('', 204);
        $this->notifierWith($http)->send(
            $this->channel('discord', ['url' => self::WEBHOOK]),
            $this->alert(),
            $this->user(),
        );

        $payload = $this->decodeLastBody($http);
        self::assertStringContainsString('Netflix renews in 7 days', (string) $payload['content']);
        self::assertStringContainsString('£10.99 is due on 1 Oct 2026.', (string) $payload['content']);
        self::assertStringContainsString('https://renovo.example/subscriptions/42/money', (string) $payload['content']);

        // Discord is public-only: the allowlist must not be able to downgrade
        // this to http.
        self::assertSame([true], $http->httpsOnlyFlags);
    }

    public function testADeletedWebhookIsReportedInWordsAUserCanActOn(): void
    {
        $http = FakeGuardedClient::returning('{"message":"Unknown Webhook","code":10015}', 404);

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/no longer exists/');

        $this->notifierWith($http)->send(
            $this->channel('discord', ['url' => self::WEBHOOK]),
            $this->alert(),
            $this->user(),
        );
    }

    public function testDiscordsOwnMessageIsUsedWhenTheStatusIsNotOneWeRephrase(): void
    {
        $http = FakeGuardedClient::returning('{"message":"Must be 2000 or fewer in length.","code":50035}', 400);

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/2000 or fewer/');

        $this->notifierWith($http)->send(
            $this->channel('discord', ['url' => self::WEBHOOK]),
            $this->alert(),
            $this->user(),
        );
    }

    public function testTheChannelUrlIsRejectedWhenItIsNotAWebhook(): void
    {
        $this->expectException(ValidationException::class);

        $this->notifierWith(FakeGuardedClient::returning())
            ->normaliseConfig(['url' => 'https://discord.com/channels/123/456']);
    }

    public function testPlainHttpIsRefusedAtTheField(): void
    {
        $this->expectException(ValidationException::class);

        $this->notifierWith(FakeGuardedClient::returning())
            ->normaliseConfig(['url' => 'http://discord.com/api/webhooks/1/2']);
    }

    public function testDescribeShowsTheHostAndNotTheWebhookToken(): void
    {
        $description = $this->notifierWith(FakeGuardedClient::returning())->describe(['url' => self::WEBHOOK]);

        self::assertSame('https://discord.com', $description);
    }
}
