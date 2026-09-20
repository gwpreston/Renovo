<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\ServerchanNotifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use App\Tests\Support\FakeGuardedClient;

final class ServerchanNotifierTest extends NotifierTestCase
{
    private const SENDKEY = 'SCT1234abcdEFGH5678';

    public function testItPostsTitleAndDespAsAFormToTheKeyedUrl(): void
    {
        $http = FakeGuardedClient::returning('{"code":0,"message":"","data":{"pushid":"1"}}');

        $this->notifier(ServerchanNotifier::class, $http)
            ->send($this->channel('serverchan', ['sendkey' => self::SENDKEY]), $this->alert(), $this->user());

        self::assertSame(
            'https://sctapi.ftqq.com/' . self::SENDKEY . '.send',
            (string) $http->lastRequest()?->getUri(),
        );

        $fields = $this->decodeLastForm($http);
        self::assertSame('Netflix renews in 7 days', $fields['title']);
        self::assertStringContainsString('£10.99', $fields['desp']);
        self::assertSame([true], $http->httpsOnlyFlags);
    }

    /**
     * The API rejects a title containing a line break outright, so the newline
     * is flattened rather than passed on.
     */
    public function testALineBreakIsStrippedOutOfTheTitle(): void
    {
        $http = FakeGuardedClient::returning('{"code":0}');
        $alert = $this->alert();

        $this->notifier(ServerchanNotifier::class, $http)->send(
            $this->channel('serverchan', ['sendkey' => self::SENDKEY]),
            new \App\Notification\Alert(
                $alert->type,
                $alert->subjectType,
                $alert->subjectId,
                $alert->occurrenceKey,
                "Netflix renews\nin 7 days",
                $alert->lines,
                $alert->url,
                $alert->dueDate,
            ),
            $this->user(),
        );

        self::assertStringNotContainsString("\n", $this->decodeLastForm($http)['title']);
    }

    /**
     * Success is `code: 0`, not a 2xx. Trusting the status alone would record a
     * rejected sendkey as a delivery.
     */
    public function testANonZeroCodeIsAFailureEvenOnHttp200(): void
    {
        $http = FakeGuardedClient::returning('{"code":40001,"message":"bad pushkey"}');

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/bad pushkey/');

        $this->notifier(ServerchanNotifier::class, $http)
            ->send($this->channel('serverchan', ['sendkey' => self::SENDKEY]), $this->alert(), $this->user());
    }

    /**
     * The reason this channel overrides `redact()`: its credential is the URL
     * path, and the transport's own exception quotes the URL it failed on. That
     * message is stored in `last_error` and rendered on the settings page.
     */
    public function testATransportFailureNeverQuotesTheSendkey(): void
    {
        $http = FakeGuardedClient::failingWithUrl();

        try {
            $this->notifier(ServerchanNotifier::class, $http)
                ->send($this->channel('serverchan', ['sendkey' => self::SENDKEY]), $this->alert(), $this->user());
            self::fail('Expected the send to fail');
        } catch (NotifierException $exception) {
            self::assertStringNotContainsString(self::SENDKEY, $exception->getMessage());
            // Still useful: the user learns it is a transport problem.
            self::assertStringContainsString('timed out', $exception->getMessage());
        }
    }

    /**
     * @dataProvider badKeys
     */
    public function testAMalformedSendkeyIsRejected(string $sendkey): void
    {
        $this->expectException(ValidationException::class);

        $this->notifier(ServerchanNotifier::class, FakeGuardedClient::returning())
            ->normaliseConfig(['sendkey' => $sendkey]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badKeys(): array
    {
        return [
            'blank' => [''],
            'wrong prefix' => ['SDT1234abcd'],
            // The key is interpolated into a URL path, so anything containing a
            // separator could redirect the request somewhere else entirely.
            'contains a slash' => ['SCT123/../../evil'],
            'contains a dot' => ['SCT123.send?x=1'],
        ];
    }

    public function testDescribeRevealsNothingBecauseTheKeyIsAlsoTheAddress(): void
    {
        $description = $this->notifier(ServerchanNotifier::class, FakeGuardedClient::returning())
            ->describe(['sendkey' => self::SENDKEY]);

        self::assertStringNotContainsString(self::SENDKEY, $description);
        self::assertNotSame('', $description);
    }
}
