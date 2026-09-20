<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\DiscordNotifier;
use App\Notification\Channel\GotifyNotifier;
use App\Notification\Channel\MattermostNotifier;
use App\Notification\Channel\NtfyNotifier;
use App\Notification\Channel\PushoverNotifier;
use App\Notification\Channel\PushplusNotifier;
use App\Notification\Channel\ServerchanNotifier;
use App\Notification\Channel\SlackNotifier;
use App\Notification\Channel\TelegramNotifier;
use App\Notification\Channel\WebhookNotifier;
use App\Notification\Notifier;
use App\Notification\NotifierException;
use App\Tests\Support\FakeGuardedClient;

/**
 * The rules that hold for every channel, checked against every channel.
 *
 * The alternative — asserting them once per class — is how a suite ends up
 * covering ten channels and missing the eleventh. Here a new channel is added
 * to `channels()` and inherits the invariants, which is the same bargain the
 * registry makes: one list, and everything else follows from it.
 *
 * Email is absent because it is not an `HttpNotifier` and has no URL, secret or
 * guarded client to reason about; it is covered by its own tests.
 *
 * @see NotifierRegistryTest for the assertion that this list matches what the
 *      container actually registers — without it, a channel could be added to
 *      the application and skipped here.
 */
final class ChannelInvariantsTest extends NotifierTestCase
{
    /**
     * Every HTTP channel, with configuration whose secrets are distinctive
     * enough to find in a string.
     *
     * @return array<string, array{class-string<Notifier>, array<string, string>, list<string>}>
     */
    public static function channels(): array
    {
        return [
            // name => [class, a valid config, the values that must never leak]
            'discord' => [
                DiscordNotifier::class,
                ['url' => 'https://discord.com/api/webhooks/123/S3cretW3bhookT0ken'],
                ['S3cretW3bhookT0ken'],
            ],
            'gotify' => [
                GotifyNotifier::class,
                ['url' => 'https://gotify.example.com', 'token' => 'AsecretToken1', 'priority' => '5'],
                ['AsecretToken1'],
            ],
            'mattermost' => [
                MattermostNotifier::class,
                ['url' => 'https://mm.example.com/hooks/s3cr3thookkey', 'channel' => 'bills'],
                ['s3cr3thookkey'],
            ],
            'ntfy' => [
                NtfyNotifier::class,
                [
                    'server' => 'https://ntfy.sh',
                    'topic' => 'renovo-alerts',
                    'token' => 'tk_s3cretAccessToken',
                    'priority' => '3',
                    'tags' => '',
                ],
                ['tk_s3cretAccessToken'],
            ],
            'pushover' => [
                PushoverNotifier::class,
                [
                    'token' => 'aaaaaaaaaabbbbbbbbbbcccccccccc',
                    'user_key' => 'ddddddddddeeeeeeeeeeffffffffff',
                    'priority' => '0',
                ],
                ['aaaaaaaaaabbbbbbbbbbcccccccccc', 'ddddddddddeeeeeeeeeeffffffffff'],
            ],
            'pushplus' => [
                PushplusNotifier::class,
                ['token' => str_repeat('a1b2', 8), 'topic' => 'household'],
                [str_repeat('a1b2', 8)],
            ],
            'serverchan' => [
                ServerchanNotifier::class,
                ['sendkey' => 'SCT12345abcdeSECRET'],
                ['SCT12345abcdeSECRET'],
            ],
            'slack' => [
                SlackNotifier::class,
                ['token' => 'xoxb-s3cret-token', 'channel' => 'C0123'],
                ['xoxb-s3cret-token'],
            ],
            'telegram' => [
                TelegramNotifier::class,
                ['token' => '123456789:AAsecretBotTokenValue', 'chat_id' => '-100987'],
                ['123456789:AAsecretBotTokenValue'],
            ],
            'webhook' => [
                WebhookNotifier::class,
                ['url' => 'https://hooks.example/renovo', 'secret' => 'sh4redSecret'],
                ['sh4redSecret'],
            ],
        ];
    }

    /**
     * @param class-string<Notifier> $class
     * @param array<string, string> $config
     * @param list<string> $secrets
     *
     * @dataProvider channels
     */
    public function testDescribeNeverLeaksASecret(string $class, array $config, array $secrets): void
    {
        $notifier = $this->notifier($class, FakeGuardedClient::returning());
        $description = $notifier->describe($config);

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $description, $notifier->key() . ' leaked a secret');
        }
    }

    /**
     * The form never renders a stored secret, so a channel being edited comes
     * back with that field blank. Blank must mean "unchanged", not "cleared" —
     * otherwise saving a label change silently destroys the credential.
     *
     * @param class-string<Notifier> $class
     * @param array<string, string> $config
     *
     * @dataProvider channels
     */
    public function testABlankSecretKeepsTheStoredValue(string $class, array $config): void
    {
        $notifier = $this->notifier($class, FakeGuardedClient::returning());

        $secretFields = [];
        foreach ($notifier->fields() as $field) {
            if ($field->isSecret) {
                $secretFields[] = $field->name;
            }
        }

        if ($secretFields === []) {
            // Discord and Mattermost carry their credential in the URL, which
            // is an ordinary field the form does re-render. Nothing to preserve
            // here, but the case is counted rather than silently skipped.
            $this->addToAssertionCount(1);

            return;
        }

        // Resubmit everything except the secrets, as the form does.
        $submitted = $config;
        foreach ($secretFields as $name) {
            $submitted[$name] = '';
        }

        $result = $notifier->normaliseConfig($submitted, $config);

        foreach ($secretFields as $name) {
            if (($config[$name] ?? '') === '') {
                continue;
            }

            self::assertSame(
                $config[$name],
                $result[$name] ?? null,
                $notifier->key() . ' wiped ' . $name . ' when the form left it blank',
            );
        }
    }

    /**
     * A refusal from the guard must arrive as an ordinary channel failure, so
     * the dispatcher records it against the channel and carries on. If one of
     * these escaped as a `BlockedTargetException`, a single misconfigured
     * channel would stop the whole scheduler run.
     *
     * @param class-string<Notifier> $class
     * @param array<string, string> $config
     *
     * @dataProvider channels
     */
    public function testABlockedDestinationBecomesAChannelFailure(string $class, array $config): void
    {
        $http = FakeGuardedClient::blocking('it resolves to a private address');
        $notifier = $this->notifier($class, $http);

        $this->expectException(NotifierException::class);

        $notifier->send($this->channel($notifier->key(), $config), $this->alert(), $this->user());
    }

    /**
     * The same refusal, checked for what it *says*. Four of these services put
     * the credential in the URL, and the transport's exception names the URL it
     * failed on — a message that is stored in `last_error` and rendered on the
     * settings page.
     *
     * @param class-string<Notifier> $class
     * @param array<string, string> $config
     * @param list<string> $secrets
     *
     * @dataProvider channels
     */
    public function testAFailureMessageNeverCarriesASecret(string $class, array $config, array $secrets): void
    {
        // The fake reproduces the real client's habit of quoting the URL.
        $http = FakeGuardedClient::failingWithUrl();
        $notifier = $this->notifier($class, $http);

        try {
            $notifier->send($this->channel($notifier->key(), $config), $this->alert(), $this->user());
            self::fail('Expected the send to fail');
        } catch (NotifierException $exception) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString(
                    $secret,
                    $exception->getMessage(),
                    $notifier->key() . ' put a secret into an error a user will read',
                );
            }
        }
    }

    /**
     * The body each service returns when it accepted the message.
     *
     * @param class-string<Notifier> $class
     *
     * They cannot be collapsed into one: Pushplus signals success with
     * `code: 200` and Serverchan with `code: 0`, so the same field means
     * opposite things depending on who answered. That disagreement is exactly
     * why each channel parses its own body instead of trusting the status.
     */
    private static function successBody(string $class): string
    {
        return match ($class) {
            SlackNotifier::class, TelegramNotifier::class => '{"ok":true}',
            PushoverNotifier::class => '{"status":1,"request":"abc"}',
            PushplusNotifier::class => '{"code":200,"msg":"ok","data":"1"}',
            ServerchanNotifier::class => '{"code":0,"message":"","data":{"pushid":"1"}}',
            default => '',
        };
    }

    /**
     * Every one of these goes through the guarded client — the type system
     * already guarantees it, but this catches a channel that sends nothing at
     * all and reports success.
     *
     * @param class-string<Notifier> $class
     * @param array<string, string> $config
     *
     * @dataProvider channels
     */
    public function testEveryChannelActuallySendsSomething(string $class, array $config): void
    {
        $http = FakeGuardedClient::returning(self::successBody($class));
        $notifier = $this->notifier($class, $http);

        $notifier->send($this->channel($notifier->key(), $config), $this->alert(), $this->user());

        self::assertCount(1, $http->requests, $notifier->key() . ' sent nothing');
        self::assertSame('POST', $http->lastRequest()?->getMethod());
    }
}
