<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Channel\EmailNotifier;
use App\Notification\Notifier;
use App\Notification\NotifierRegistry;
use App\Tests\Support\FakeGuardedClient;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The registry is the only list of channel types in the application, which
 * makes it the only place that can tell a test what the application actually
 * ships.
 *
 * The second test is the one that matters: it holds `ChannelInvariantsTest` to
 * the container's list. Without it, adding a channel to `container.php` and
 * forgetting to add it to the invariants provider would leave that channel
 * unchecked for everything that must be true of all of them — and nothing would
 * go red.
 */
final class NotifierRegistryTest extends TestCase
{
    public function testEveryRegisteredKeyIsUnique(): void
    {
        $registry = new NotifierRegistry($this->notifiers());
        $keys = array_map(static fn (Notifier $n): string => $n->key(), $registry->all());

        self::assertSame(array_unique($keys), $keys, 'Two channels share a key; one would shadow the other.');
        self::assertCount(count($this->notifiers()), $keys);
    }

    public function testEveryChannelInTheContainerIsCoveredByTheInvariants(): void
    {
        $covered = array_map(
            static fn (array $case): string => (string) $case[0],
            array_values(ChannelInvariantsTest::channels()),
        );

        $uncovered = array_values(array_filter(
            $this->channelClasses(),
            static fn (string $class): bool => !in_array($class, $covered, true),
        ));

        self::assertSame(
            [],
            $uncovered,
            'Registered but not covered by ChannelInvariantsTest: ' . implode(', ', $uncovered),
        );
    }

    public function testTheRegistryResolvesEveryChannelByItsStoredKey(): void
    {
        $registry = new NotifierRegistry($this->notifiers());

        foreach ($this->notifiers() as $notifier) {
            self::assertTrue($registry->has($notifier->key()));
            self::assertSame($notifier->key(), $registry->get($notifier->key())->key());
        }

        // A stored row whose type was removed must not be fatal to look up.
        self::assertNull($registry->find('a-channel-that-was-removed'));
    }

    /**
     * The channel classes the container registers, read out of the container
     * definition itself rather than restated here — a second hand-maintained
     * list would drift from the first and prove nothing.
     *
     * @return list<class-string<Notifier>>
     */
    private function channelClasses(): array
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../config/container.php');

        self::assertSame(1, preg_match('/new NotifierRegistry\(\[(.*?)\]\)/s', $source, $match));
        self::assertGreaterThan(0, preg_match_all('/\$c->get\((\w+)::class\)/', $match[1], $found));

        $classes = [];
        foreach ($found[1] as $short) {
            /** @var class-string<Notifier> $fqcn */
            $fqcn = 'App\\Notification\\Channel\\' . $short;
            self::assertTrue(class_exists($fqcn), $fqcn . ' is registered but does not exist.');

            // Email has no URL, no secret and no guarded client, so the HTTP
            // invariants do not apply to it; it has its own tests.
            if ($fqcn === EmailNotifier::class) {
                continue;
            }

            $classes[] = $fqcn;
        }

        self::assertNotSame([], $classes, 'No channels were found in the container definition.');

        return $classes;
    }

    /**
     * @return list<Notifier>
     */
    private function notifiers(): array
    {
        $notifiers = [];
        foreach (ChannelInvariantsTest::channels() as $case) {
            /** @var class-string<Notifier> $class */
            $class = $case[0];
            $notifiers[] = new $class(
                FakeGuardedClient::returning(),
                new RequestFactory(),
                new StreamFactory(),
            );
        }

        return $notifiers;
    }
}
