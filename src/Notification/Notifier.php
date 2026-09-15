<?php

declare(strict_types=1);

namespace App\Notification;

use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\User;

/**
 * One way of getting a message to somebody.
 *
 * The contract is deliberately small, because the promise made in CLAUDE.md is
 * that a new channel costs one class and a line in the registry — no change to
 * the dispatcher, the scheduler, the settings form or the database. Everything
 * a channel type differs in is expressed through these five methods:
 *
 *  - `fields()` describes its configuration, and the settings form renders it.
 *  - `normaliseConfig()` validates what came back, and is the only place that
 *    knows what a valid Gotify URL or Slack channel id looks like.
 *  - `describe()` produces the one-line summary shown next to the channel,
 *    with no secret in it.
 *  - `send()` delivers, or throws NotifierException.
 *
 * Any implementation that fetches a URL the user supplied must do so through
 * the guarded HTTP client. SMTP is the exception and is exempt: its host comes
 * from the operator's environment, not from a form.
 */
interface Notifier
{
    /**
     * The stable key stored in `notification_channels.channel_type`.
     */
    public function key(): string;

    public function label(): string;

    /**
     * @return list<ChannelField>
     */
    public function fields(): array;

    /**
     * Validate and normalise submitted configuration.
     *
     * @param array<string, string> $input    What the user submitted.
     * @param array<string, string> $existing The stored config, so that a
     *        secret left blank keeps its current value rather than being wiped
     *        by a form that never showed it.
     * @return array<string, string>
     * @throws \App\Service\ValidationException
     */
    public function normaliseConfig(array $input, array $existing = []): array;

    /**
     * A short description of where this channel points, safe to display.
     *
     * @param array<string, string> $config
     */
    public function describe(array $config): string;

    /**
     * @throws NotifierException when delivery fails.
     */
    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void;
}
