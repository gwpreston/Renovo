<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\AlertType;
use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\User;
use App\Notification\Alert;
use App\Notification\Channel\GotifyNotifier;
use App\Notification\Channel\SlackNotifier;
use App\Notification\Channel\WebhookNotifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use App\Tests\Support\FakeGuardedClient;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * What each channel actually puts on the wire, and how it reads the answer.
 */
final class NotifierTest extends TestCase
{
    /**
     * @param array<string, string> $config
     */
    private function channel(string $type, array $config): NotificationChannel
    {
        $now = new DateTimeImmutable('2026-09-15 09:00:00');

        return new NotificationChannel(1, 7, $type, 'Test channel', $config, true, null, null, $now, $now);
    }

    private function alert(): Alert
    {
        return new Alert(
            AlertType::Renewal,
            Alert::SUBJECT_SUBSCRIPTION,
            42,
            '2026-10-01:7',
            'Netflix renews in 7 days',
            ['£10.99 is due on 1 Oct 2026.'],
            'https://renovo.example/subscriptions/42/money',
            new DateTimeImmutable('2026-10-01'),
        );
    }

    private function user(): User
    {
        return new User(7, 'sam@example.com', 'Sam', 'hash', false, null, 'system', new DateTimeImmutable());
    }

    // ------------------------------------------------------------------
    // Gotify
    // ------------------------------------------------------------------

    public function testGotifySendsTheTokenInAHeaderRatherThanTheUrl(): void
    {
        $http = FakeGuardedClient::returning('{}');
        $notifier = new GotifyNotifier($http, new RequestFactory(), new StreamFactory());

        $notifier->send(
            $this->channel('gotify', [
                'url' => 'https://gotify.example.com',
                'token' => 'AsecretToken1',
                'priority' => '8',
            ]),
            $this->alert(),
            $this->user(),
        );

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertSame('https://gotify.example.com/message', (string) $request->getUri());
        self::assertSame('AsecretToken1', $request->getHeaderLine('X-Gotify-Key'));
        // The secret must not be in the URL, where it would be logged by every
        // proxy and web server on the way.
        self::assertStringNotContainsString('AsecretToken1', (string) $request->getUri());

        $payload = json_decode($http->lastBody(), true);
        self::assertSame('Netflix renews in 7 days', $payload['title']);
        self::assertSame(8, $payload['priority']);
        self::assertStringContainsString('£10.99 is due', $payload['message']);
    }

    public function testGotifyReportsARejectedToken(): void
    {
        $http = FakeGuardedClient::returning('', 401);
        $notifier = new GotifyNotifier($http, new RequestFactory(), new StreamFactory());

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/token was not accepted/');

        $notifier->send(
            $this->channel('gotify', ['url' => 'https://gotify.example.com', 'token' => 'bad']),
            $this->alert(),
            $this->user(),
        );
    }

    public function testGotifyKeepsAnExistingTokenWhenTheFieldIsLeftBlank(): void
    {
        $notifier = new GotifyNotifier(FakeGuardedClient::returning(), new RequestFactory(), new StreamFactory());

        $config = $notifier->normaliseConfig(
            ['url' => 'https://gotify.example.com/', 'token' => '', 'priority' => ''],
            ['token' => 'AexistingToken'],
        );

        // The form never shows a stored secret, so a blank field cannot mean
        // "clear it" without wiping the token on every unrelated edit.
        self::assertSame('AexistingToken', $config['token']);
        self::assertSame('https://gotify.example.com', $config['url']);
        self::assertSame('5', $config['priority']);
    }

    public function testGotifyRejectsAUrlThatIsNotOne(): void
    {
        $notifier = new GotifyNotifier(FakeGuardedClient::returning(), new RequestFactory(), new StreamFactory());

        $this->expectException(ValidationException::class);
        $notifier->normaliseConfig(['url' => 'not a url', 'token' => 'x']);
    }

    // ------------------------------------------------------------------
    // Slack
    // ------------------------------------------------------------------

    public function testSlackPostsToChatPostMessageOverHttpsOnly(): void
    {
        $http = FakeGuardedClient::returningJson(['ok' => true]);
        $notifier = new SlackNotifier($http, new RequestFactory(), new StreamFactory());

        $notifier->send(
            $this->channel('slack', ['token' => 'xoxb-secret', 'channel' => 'C0123']),
            $this->alert(),
            $this->user(),
        );

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertSame('https://slack.com/api/chat.postMessage', (string) $request->getUri());
        self::assertSame('Bearer xoxb-secret', $request->getHeaderLine('Authorization'));
        self::assertSame([true], $http->httpsOnlyFlags);

        $payload = json_decode($http->lastBody(), true);
        self::assertSame('C0123', $payload['channel']);
        self::assertStringContainsString('Netflix renews in 7 days', $payload['text']);
    }

    public function testSlackTreatsAnOkFalseBodyAsAFailureDespiteHttp200(): void
    {
        // The single most important thing about this channel: Slack reports
        // almost every error with a perfectly successful HTTP status. A
        // notifier that looked only at the code would mark a message that was
        // never delivered as sent, and the ledger would never retry it.
        $http = FakeGuardedClient::returningJson(['ok' => false, 'error' => 'not_in_channel'], 200);
        $notifier = new SlackNotifier($http, new RequestFactory(), new StreamFactory());

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/not been invited/');

        $notifier->send(
            $this->channel('slack', ['token' => 'xoxb-secret', 'channel' => 'C0123']),
            $this->alert(),
            $this->user(),
        );
    }

    public function testSlackExplainsAnUnknownErrorRatherThanSwallowingIt(): void
    {
        $http = FakeGuardedClient::returningJson(['ok' => false, 'error' => 'ratelimited'], 200);
        $notifier = new SlackNotifier($http, new RequestFactory(), new StreamFactory());

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/ratelimited/');

        $notifier->send(
            $this->channel('slack', ['token' => 'xoxb-secret', 'channel' => 'C0123']),
            $this->alert(),
            $this->user(),
        );
    }

    // ------------------------------------------------------------------
    // Webhook
    // ------------------------------------------------------------------

    public function testWebhookPostsAStructuredPayload(): void
    {
        $http = FakeGuardedClient::returning('', 204);
        $notifier = new WebhookNotifier($http, new RequestFactory(), new StreamFactory());

        $notifier->send(
            $this->channel('webhook', ['url' => 'https://hooks.example/renovo', 'secret' => '']),
            $this->alert(),
            $this->user(),
        );

        $payload = json_decode($http->lastBody(), true);
        self::assertSame('renewal', $payload['type']);
        self::assertSame(42, $payload['subject_id']);
        self::assertSame('2026-10-01', $payload['due_date']);
        self::assertFalse($http->lastRequest()?->hasHeader('X-Renovo-Signature'));
    }

    public function testWebhookSignsTheExactBytesItSends(): void
    {
        $http = FakeGuardedClient::returning('', 200);
        $notifier = new WebhookNotifier($http, new RequestFactory(), new StreamFactory());

        $notifier->send(
            $this->channel('webhook', ['url' => 'https://hooks.example/renovo', 'secret' => 'sh4red']),
            $this->alert(),
            $this->user(),
        );

        $signature = $http->lastRequest()?->getHeaderLine('X-Renovo-Signature') ?? '';

        // The receiver verifies the signature against the body it received, so
        // the signature has to be over exactly those bytes — not over a second,
        // separately encoded copy that might differ by a flag.
        self::assertSame('sha256=' . hash_hmac('sha256', $http->lastBody(), 'sh4red'), $signature);
    }

    public function testWebhookReportsANonSuccessStatus(): void
    {
        $http = FakeGuardedClient::returning('', 500);
        $notifier = new WebhookNotifier($http, new RequestFactory(), new StreamFactory());

        $this->expectException(NotifierException::class);

        $notifier->send(
            $this->channel('webhook', ['url' => 'https://hooks.example/renovo']),
            $this->alert(),
            $this->user(),
        );
    }

    public function testABlockedDestinationBecomesAChannelFailureRatherThanAnEscape(): void
    {
        // The guard's refusal must arrive as an ordinary channel failure, so
        // the dispatcher records it and moves on instead of the scheduler
        // stopping on it.
        $http = FakeGuardedClient::blocking('it resolves to a private address');
        $notifier = new WebhookNotifier($http, new RequestFactory(), new StreamFactory());

        $this->expectException(NotifierException::class);

        $notifier->send(
            $this->channel('webhook', ['url' => 'https://internal.example/hook']),
            $this->alert(),
            $this->user(),
        );
    }

    public function testDescriptionsNeverIncludeASecret(): void
    {
        $gotify = new GotifyNotifier(FakeGuardedClient::returning(), new RequestFactory(), new StreamFactory());
        $webhook = new WebhookNotifier(FakeGuardedClient::returning(), new RequestFactory(), new StreamFactory());
        $slack = new SlackNotifier(FakeGuardedClient::returning(), new RequestFactory(), new StreamFactory());

        self::assertStringNotContainsString(
            'AsecretToken1',
            $gotify->describe(['url' => 'https://gotify.example.com', 'token' => 'AsecretToken1']),
        );
        self::assertStringNotContainsString(
            'sh4red',
            $webhook->describe(['url' => 'https://hooks.example/renovo', 'secret' => 'sh4red']),
        );
        self::assertStringNotContainsString(
            'xoxb-secret',
            $slack->describe(['token' => 'xoxb-secret', 'channel' => 'C0123']),
        );
    }
}
