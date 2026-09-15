<?php

declare(strict_types=1);

namespace App\Notification;

use RuntimeException;

/**
 * Every channel type the instance knows how to send through.
 *
 * Registration happens once, in the container. Nothing else in the application
 * names a channel type: the settings form asks this for the list, the
 * dispatcher asks it for the notifier matching a stored row, and both keep
 * working when a type is added.
 */
final class NotifierRegistry
{
    /** @var array<string, Notifier> */
    private array $notifiers = [];

    /**
     * @param list<Notifier> $notifiers
     */
    public function __construct(array $notifiers)
    {
        foreach ($notifiers as $notifier) {
            $this->notifiers[$notifier->key()] = $notifier;
        }
    }

    /**
     * @return list<Notifier>
     */
    public function all(): array
    {
        return array_values($this->notifiers);
    }

    public function has(string $key): bool
    {
        return isset($this->notifiers[$key]);
    }

    public function get(string $key): Notifier
    {
        return $this->notifiers[$key] ?? throw new RuntimeException(sprintf(
            'No notifier is registered for "%s".',
            $key,
        ));
    }

    public function find(string $key): ?Notifier
    {
        return $this->notifiers[$key] ?? null;
    }
}
