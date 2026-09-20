<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\NtfyNotifier;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use App\Tests\Support\FakeGuardedClient;

/**
 * ntfy is the phase's only channel whose transport rule is computed, so that
 * rule gets tested on both sides rather than asserted once.
 */
final class NtfyNotifierTest extends NotifierTestCase
{
    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function config(array $overrides = []): array
    {
        return $overrides + [
            'server' => 'https://ntfy.sh',
            'topic' => 'renovo-alerts',
            'token' => '',
            'priority' => '3',
            'tags' => '',
        ];
    }

    public function testItPublishesAsJsonToTheServerRootWithTheTopicInTheBody(): void
    {
        $http = FakeGuardedClient::returning('{"id":"abc"}');

        $this->notifier(NtfyNotifier::class, $http)
            ->send($this->channel('ntfy', $this->config()), $this->alert(), $this->user());

        // Not `https://ntfy.sh/renovo-alerts`: the title travels in the JSON
        // body precisely so a non-ASCII title is not forced through a latin-1
        // header.
        self::assertSame('https://ntfy.sh', (string) $http->lastRequest()?->getUri());

        $payload = $this->decodeLastBody($http);
        self::assertSame('renovo-alerts', $payload['topic']);
        self::assertSame('Netflix renews in 7 days', $payload['title']);
        self::assertSame(3, $payload['priority']);
        self::assertSame('https://renovo.example/subscriptions/42/money', $payload['click']);
    }

    public function testANonAsciiTitleSurvivesIntact(): void
    {
        $http = FakeGuardedClient::returning('{}');
        $alert = $this->alert();

        $this->notifier(NtfyNotifier::class, $http)->send(
            $this->channel('ntfy', $this->config()),
            $alert->withLines(['Café Nero — ￥1,200 は 10月1日 に引き落とされます。']),
            $this->user(),
        );

        $payload = $this->decodeLastBody($http);
        self::assertStringContainsString('Café Nero', (string) $payload['message']);
        self::assertStringContainsString('引き落とされます', (string) $payload['message']);
    }

    // ------------------------------------------------------------------
    // The computed transport rule
    // ------------------------------------------------------------------

    public function testThePublicServiceForcesHttps(): void
    {
        $http = FakeGuardedClient::returning('{}');

        $this->notifier(NtfyNotifier::class, $http)
            ->send($this->channel('ntfy', $this->config()), $this->alert(), $this->user());

        self::assertSame([true], $http->httpsOnlyFlags);
    }

    public function testASelfHostedServerDefersToTheAllowlistInstead(): void
    {
        $http = FakeGuardedClient::returning('{}');

        $this->notifier(NtfyNotifier::class, $http)->send(
            $this->channel('ntfy', $this->config(['server' => 'http://ntfy.lan:8080'])),
            $this->alert(),
            $this->user(),
        );

        // False, not true: a self-hosted ntfy on a LAN is the common case, and
        // forcing https here would make it unusable. The guard still refuses it
        // unless an administrator has trusted the host.
        self::assertSame([false], $http->httpsOnlyFlags);
        self::assertSame('http://ntfy.lan:8080', (string) $http->lastRequest()?->getUri());
    }

    public function testASubdomainOfThePublicServiceIsStillTreatedAsPublic(): void
    {
        $notifier = $this->notifier(NtfyNotifier::class, FakeGuardedClient::returning());

        self::assertTrue($notifier->httpsOnly('https://ntfy.sh'));
        self::assertTrue($notifier->httpsOnly('https://eu.ntfy.sh'));
        self::assertFalse($notifier->httpsOnly('https://ntfy.example.com'));
        // The check is on the host, not the string: a lookalike must not pass.
        self::assertFalse($notifier->httpsOnly('https://notntfy.sh'));
        self::assertFalse($notifier->httpsOnly('https://ntfy.sh.evil.test'));
    }

    // ------------------------------------------------------------------
    // Token handling
    // ------------------------------------------------------------------

    public function testAnAccessTokenTravelsAsABearerHeader(): void
    {
        $http = FakeGuardedClient::returning('{}');

        $this->notifier(NtfyNotifier::class, $http)->send(
            $this->channel('ntfy', $this->config(['token' => 'tk_secret'])),
            $this->alert(),
            $this->user(),
        );

        self::assertSame('Bearer tk_secret', $http->lastRequest()?->getHeaderLine('Authorization'));
    }

    public function testNoTokenMeansNoAuthorizationHeaderAtAll(): void
    {
        $http = FakeGuardedClient::returning('{}');

        $this->notifier(NtfyNotifier::class, $http)
            ->send($this->channel('ntfy', $this->config()), $this->alert(), $this->user());

        // An empty `Authorization: Bearer ` is malformed, not anonymous.
        self::assertFalse($http->lastRequest()?->hasHeader('Authorization'));
    }

    public function testAProtectedTopicReportsTheAuthFailureUsefully(): void
    {
        $http = FakeGuardedClient::returning('', 403);

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/may not publish to this topic/');

        $this->notifier(NtfyNotifier::class, $http)
            ->send($this->channel('ntfy', $this->config(['token' => 'tk_wrong'])), $this->alert(), $this->user());
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function testABlankServerFallsBackToThePublicOne(): void
    {
        $config = $this->notifier(NtfyNotifier::class, FakeGuardedClient::returning())
            ->normaliseConfig(['server' => '', 'topic' => 'bills']);

        self::assertSame('https://ntfy.sh', $config['server']);
    }

    public function testTagsBecomeAStringArrayRatherThanACommaSeparatedString(): void
    {
        $http = FakeGuardedClient::returning('{}');

        $this->notifier(NtfyNotifier::class, $http)->send(
            $this->channel('ntfy', $this->config(['tags' => 'warning, money'])),
            $this->alert(),
            $this->user(),
        );

        // The JSON schema takes an array; the comma-separated form belongs to
        // the `X-Tags` header, which this channel does not use.
        self::assertSame(['warning', 'money'], $this->decodeLastBody($http)['tags']);
    }

    /**
     * @dataProvider badConfigurations
     * @param array<string, string> $input
     */
    public function testMalformedConfigurationIsRejected(array $input): void
    {
        $this->expectException(ValidationException::class);

        $this->notifier(NtfyNotifier::class, FakeGuardedClient::returning())->normaliseConfig($input);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function badConfigurations(): array
    {
        return [
            'no topic' => [['server' => 'https://ntfy.sh', 'topic' => '']],
            'topic with a slash' => [['server' => 'https://ntfy.sh', 'topic' => 'bills/../admin']],
            'priority out of range' => [['server' => '', 'topic' => 'bills', 'priority' => '9']],
            'not a URL' => [['server' => 'not a url', 'topic' => 'bills']],
        ];
    }
}
