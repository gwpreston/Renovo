<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\TelegramNotifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use App\Tests\Support\FakeGuardedClient;

final class TelegramNotifierTest extends NotifierTestCase
{
    private const TOKEN = '123456789:AAEhBOweik6ad9r_TESTTOKEN';

    /**
     * @return array<string, string>
     */
    private function config(): array
    {
        return ['token' => self::TOKEN, 'chat_id' => '-1001234567890'];
    }

    public function testItCallsSendMessageWithTheTokenInThePathAndTheChatInTheBody(): void
    {
        $http = FakeGuardedClient::returning('{"ok":true,"result":{"message_id":1}}');

        $this->notifier(TelegramNotifier::class, $http)
            ->send($this->channel('telegram', $this->config()), $this->alert(), $this->user());

        self::assertSame(
            'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage',
            (string) $http->lastRequest()?->getUri(),
        );

        $payload = $this->decodeLastBody($http);
        self::assertSame('-1001234567890', $payload['chat_id']);
        self::assertStringContainsString('Netflix renews in 7 days', (string) $payload['text']);
        self::assertSame([true], $http->httpsOnlyFlags);
    }

    /**
     * The trap this channel shares with Slack: HTTP 200 with a body that says
     * the message was not sent.
     */
    public function testAnOkFalseBodyIsAFailureEvenOnHttp200(): void
    {
        $http = FakeGuardedClient::returning(
            '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}',
        );

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/chat could not be found/');

        $this->notifier(TelegramNotifier::class, $http)
            ->send($this->channel('telegram', $this->config()), $this->alert(), $this->user());
    }

    public function testAnUnrecognisedDescriptionIsPassedThroughRatherThanSwallowed(): void
    {
        $http = FakeGuardedClient::returning('{"ok":false,"description":"Too Many Requests: retry after 30"}');

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/retry after 30/');

        $this->notifier(TelegramNotifier::class, $http)
            ->send($this->channel('telegram', $this->config()), $this->alert(), $this->user());
    }

    public function testABadTokenIsRephrased(): void
    {
        $http = FakeGuardedClient::returning('{"ok":false,"error_code":401,"description":"Unauthorized"}');

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/bot token was not accepted/');

        $this->notifier(TelegramNotifier::class, $http)
            ->send($this->channel('telegram', $this->config()), $this->alert(), $this->user());
    }

    /**
     * @dataProvider badConfigurations
     * @param array<string, string> $input
     */
    public function testMalformedConfigurationIsRejected(array $input): void
    {
        $this->expectException(ValidationException::class);

        $this->notifier(TelegramNotifier::class, FakeGuardedClient::returning())->normaliseConfig($input);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function badConfigurations(): array
    {
        return [
            'no token' => [['token' => '', 'chat_id' => '123']],
            'token without the bot id' => [['token' => 'AAEhBOweik6ad9r', 'chat_id' => '123']],
            'token with a slash, which would rewrite the URL' => [
                ['token' => '123:abc/../../evil', 'chat_id' => '123'],
            ],
            'no chat id' => [['token' => self::TOKEN, 'chat_id' => '']],
            'chat id that is neither numeric nor @name' => [['token' => self::TOKEN, 'chat_id' => 'my chat']],
        ];
    }

    public function testAPublicChannelNameIsAccepted(): void
    {
        $config = $this->notifier(TelegramNotifier::class, FakeGuardedClient::returning())
            ->normaliseConfig(['token' => self::TOKEN, 'chat_id' => '@renovo_alerts']);

        self::assertSame('@renovo_alerts', $config['chat_id']);
    }

    public function testDescribeShowsTheChatAndNotTheToken(): void
    {
        self::assertSame(
            '-1001234567890',
            $this->notifier(TelegramNotifier::class, FakeGuardedClient::returning())->describe($this->config()),
        );
    }
}
