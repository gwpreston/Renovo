<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\PushplusNotifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use App\Tests\Support\FakeGuardedClient;

final class PushplusNotifierTest extends NotifierTestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    public function testItPostsTheAlertAsPlainTextJson(): void
    {
        $http = FakeGuardedClient::returning('{"code":200,"msg":"ok","data":"1"}');

        $this->notifier(PushplusNotifier::class, $http)->send(
            $this->channel('pushplus', ['token' => self::TOKEN, 'topic' => '']),
            $this->alert(),
            $this->user(),
        );

        self::assertSame('https://api.pushplus.plus/send', (string) $http->lastRequest()?->getUri());

        $payload = $this->decodeLastBody($http);
        self::assertSame(self::TOKEN, $payload['token']);
        self::assertSame('Netflix renews in 7 days', $payload['title']);
        // `txt`, not the default `html` — an alert body is plain text with
        // newlines, and as HTML the line breaks would vanish.
        self::assertSame('txt', $payload['template']);
        self::assertSame([true], $http->httpsOnlyFlags);
    }

    public function testATopicIsSentOnlyWhenOneIsConfigured(): void
    {
        $http = FakeGuardedClient::returning('{"code":200}');

        $this->notifier(PushplusNotifier::class, $http)->send(
            $this->channel('pushplus', ['token' => self::TOKEN, 'topic' => '']),
            $this->alert(),
            $this->user(),
        );

        self::assertArrayNotHasKey('topic', $this->decodeLastBody($http));

        $http = FakeGuardedClient::returning('{"code":200}');
        $this->notifier(PushplusNotifier::class, $http)->send(
            $this->channel('pushplus', ['token' => self::TOKEN, 'topic' => 'household']),
            $this->alert(),
            $this->user(),
        );

        self::assertSame('household', $this->decodeLastBody($http)['topic']);
    }

    /**
     * The trap: Pushplus answers HTTP 200 and puts the real outcome in `code`.
     */
    public function testANonTwoHundredCodeIsAFailureEvenOnHttp200(): void
    {
        $http = FakeGuardedClient::returning('{"code":401,"msg":"token无效","data":null}');

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/token was not accepted/');

        $this->notifier(PushplusNotifier::class, $http)->send(
            $this->channel('pushplus', ['token' => self::TOKEN, 'topic' => '']),
            $this->alert(),
            $this->user(),
        );
    }

    public function testPushplusOwnMessageIsPassedThroughForAnUnknownCode(): void
    {
        $http = FakeGuardedClient::returning('{"code":600,"msg":"每日发送量已达上限","data":null}');

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/每日发送量已达上限/u');

        $this->notifier(PushplusNotifier::class, $http)->send(
            $this->channel('pushplus', ['token' => self::TOKEN, 'topic' => '']),
            $this->alert(),
            $this->user(),
        );
    }

    public function testAMalformedTokenIsRejectedAtTheField(): void
    {
        $this->expectException(ValidationException::class);

        $this->notifier(PushplusNotifier::class, FakeGuardedClient::returning())
            ->normaliseConfig(['token' => 'not-a-token']);
    }

    public function testDescribeNamesTheTopicButNeverTheToken(): void
    {
        $notifier = $this->notifier(PushplusNotifier::class, FakeGuardedClient::returning());

        self::assertSame('Pushplus', $notifier->describe(['token' => self::TOKEN, 'topic' => '']));
        self::assertSame(
            'Pushplus: household',
            $notifier->describe(['token' => self::TOKEN, 'topic' => 'household']),
        );
    }
}
