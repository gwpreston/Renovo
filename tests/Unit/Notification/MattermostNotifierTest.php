<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\MattermostNotifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use App\Tests\Support\FakeGuardedClient;

final class MattermostNotifierTest extends NotifierTestCase
{
    private const HOOK = 'https://mattermost.example.com/hooks/xxx-generatedkey-xxx';

    public function testItPostsTheTextToTheWebhook(): void
    {
        $http = FakeGuardedClient::returning('ok');

        $this->notifier(MattermostNotifier::class, $http)->send(
            $this->channel('mattermost', ['url' => self::HOOK, 'channel' => '']),
            $this->alert(),
            $this->user(),
        );

        self::assertSame(self::HOOK, (string) $http->lastRequest()?->getUri());
        self::assertStringContainsString('Netflix renews in 7 days', (string) $this->decodeLastBody($http)['text']);
    }

    /**
     * The distinction that decides this channel: Mattermost is usually
     * self-hosted, so https is *not* forced and the administrator's
     * trusted-host list is what permits a private address. Forcing https here
     * would make the commonest deployment unusable.
     */
    public function testItDoesNotForceHttpsBecauseASelfHostedServerMayBeOnTheLan(): void
    {
        $http = FakeGuardedClient::returning('ok');

        $this->notifier(MattermostNotifier::class, $http)->send(
            $this->channel('mattermost', ['url' => 'http://mattermost.lan/hooks/abc', 'channel' => '']),
            $this->alert(),
            $this->user(),
        );

        self::assertSame([false], $http->httpsOnlyFlags);
    }

    public function testAnEmptyChannelIsOmittedRatherThanSentAsBlank(): void
    {
        $http = FakeGuardedClient::returning('ok');

        $this->notifier(MattermostNotifier::class, $http)->send(
            $this->channel('mattermost', ['url' => self::HOOK, 'channel' => '']),
            $this->alert(),
            $this->user(),
        );

        // A blank `channel` would override the webhook's own default with
        // nothing, and be rejected.
        self::assertArrayNotHasKey('channel', $this->decodeLastBody($http));
    }

    public function testAConfiguredChannelOverridesTheWebhookDefault(): void
    {
        $http = FakeGuardedClient::returning('ok');

        $this->notifier(MattermostNotifier::class, $http)->send(
            $this->channel('mattermost', ['url' => self::HOOK, 'channel' => 'bills']),
            $this->alert(),
            $this->user(),
        );

        self::assertSame('bills', $this->decodeLastBody($http)['channel']);
    }

    public function testADeletedWebhookIsExplained(): void
    {
        $http = FakeGuardedClient::returning('', 404);

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/no longer exists/');

        $this->notifier(MattermostNotifier::class, $http)->send(
            $this->channel('mattermost', ['url' => self::HOOK, 'channel' => '']),
            $this->alert(),
            $this->user(),
        );
    }

    public function testAServerUrlWithoutAHookPathIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->notifier(MattermostNotifier::class, FakeGuardedClient::returning())
            ->normaliseConfig(['url' => 'https://mattermost.example.com/']);
    }

    public function testDescribeShowsTheServerButNotTheHookKey(): void
    {
        $description = $this->notifier(MattermostNotifier::class, FakeGuardedClient::returning())
            ->describe(['url' => self::HOOK, 'channel' => 'bills']);

        self::assertSame('https://mattermost.example.com · bills', $description);
    }
}
