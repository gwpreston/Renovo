<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\PushoverNotifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use App\Tests\Support\FakeGuardedClient;

final class PushoverNotifierTest extends NotifierTestCase
{
    private const TOKEN = 'azGDORePK8gMaC0QOYAMyEEuzJnyUi';
    private const USER_KEY = 'uQiRzpo4DXghDmr9QzzfQu27cmVRsG';

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function config(array $overrides = []): array
    {
        return $overrides + ['token' => self::TOKEN, 'user_key' => self::USER_KEY, 'priority' => '0'];
    }

    public function testItPostsAFormBodyWithBothCredentials(): void
    {
        $http = FakeGuardedClient::returning('{"status":1,"request":"abc"}');

        $this->notifier(PushoverNotifier::class, $http)
            ->send($this->channel('pushover', $this->config()), $this->alert(), $this->user());

        self::assertSame(
            'application/x-www-form-urlencoded',
            $http->lastRequest()?->getHeaderLine('Content-Type'),
        );

        $fields = $this->decodeLastForm($http);
        self::assertSame(self::TOKEN, $fields['token']);
        self::assertSame(self::USER_KEY, $fields['user']);
        self::assertSame('Netflix renews in 7 days', $fields['title']);
        self::assertSame('https://renovo.example/subscriptions/42/money', $fields['url']);
        self::assertSame([true], $http->httpsOnlyFlags);
    }

    /**
     * Pushover's errors are already written for a human, so they are passed
     * through rather than replaced with a status code.
     */
    public function testItRepeatsPushoversOwnExplanation(): void
    {
        $http = FakeGuardedClient::returning(
            '{"status":0,"errors":["user identifier is invalid"],"request":"abc"}',
            400,
        );

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/user identifier is invalid/');

        $this->notifier(PushoverNotifier::class, $http)
            ->send($this->channel('pushover', $this->config()), $this->alert(), $this->user());
    }

    public function testAStatusOtherThanOneIsAFailureEvenWithA200(): void
    {
        $http = FakeGuardedClient::returning('{"status":0,"errors":[]}');

        $this->expectException(NotifierException::class);

        $this->notifier(PushoverNotifier::class, $http)
            ->send($this->channel('pushover', $this->config()), $this->alert(), $this->user());
    }

    public function testEmergencyPriorityIsRefused(): void
    {
        // Priority 2 repeats until acknowledged and needs retry/expire; see the
        // class comment on PushoverNotifier.
        $this->expectException(ValidationException::class);

        $this->notifier(PushoverNotifier::class, FakeGuardedClient::returning())
            ->normaliseConfig($this->config(['priority' => '2']));
    }

    public function testQuietPrioritiesAreAllowed(): void
    {
        $config = $this->notifier(PushoverNotifier::class, FakeGuardedClient::returning())
            ->normaliseConfig($this->config(['priority' => '-2']));

        self::assertSame('-2', $config['priority']);
    }

    public function testAMistypedKeyIsCaughtAtTheFieldRatherThanAtSendTime(): void
    {
        $this->expectException(ValidationException::class);

        $this->notifier(PushoverNotifier::class, FakeGuardedClient::returning())
            ->normaliseConfig(['token' => 'too-short', 'user_key' => self::USER_KEY]);
    }

    public function testDescribeNamesTheServiceBecauseBothFieldsAreCredentials(): void
    {
        self::assertSame(
            'Pushover',
            $this->notifier(PushoverNotifier::class, FakeGuardedClient::returning())->describe($this->config()),
        );
    }
}
