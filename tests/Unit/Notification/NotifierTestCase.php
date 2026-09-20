<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Domain\AlertType;
use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\User;
use App\Http\GuardedClient;
use App\Notification\Alert;
use App\Notification\Notifier;
use App\Tests\Support\FakeGuardedClient;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The fixtures every channel's test needs: a channel row, an alert and a
 * recipient.
 *
 * Shared rather than repeated because the alert in particular is the thing
 * being rendered eleven different ways, and a test that quietly used a
 * different alert from its neighbours would compare the wrong things.
 */
abstract class NotifierTestCase extends TestCase
{
    /**
     * @param array<string, string> $config
     */
    protected function channel(string $type, array $config): NotificationChannel
    {
        $now = new DateTimeImmutable('2026-09-15 09:00:00');

        return new NotificationChannel(1, 7, $type, 'Test channel', $config, true, null, null, $now, $now);
    }

    protected function alert(): Alert
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

    protected function user(): User
    {
        return new User(7, 'sam@example.com', 'Sam', 'hash', false, null, 'system', new DateTimeImmutable());
    }

    /**
     * @template T of Notifier
     * @param class-string<T> $class
     * @return T
     */
    protected function notifier(string $class, GuardedClient $http): Notifier
    {
        return new $class($http, new RequestFactory(), new StreamFactory());
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeLastBody(FakeGuardedClient $http): array
    {
        $decoded = json_decode($http->lastBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    protected function decodeLastForm(FakeGuardedClient $http): array
    {
        parse_str($http->lastBody(), $fields);

        /** @var array<string, string> $fields */
        return $fields;
    }
}
