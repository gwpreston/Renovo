<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * One field a channel needs from the user, described by the notifier that needs
 * it so that the settings form can render any channel type without knowing
 * what it is.
 *
 * `isSecret` means the value is never sent back to the browser. A token that is
 * already stored shows as configured and can be replaced, but a form that
 * re-rendered it would put every user's credentials into the HTML of a page
 * they might screen-share.
 */
final class ChannelField
{
    /**
     * `label` and `hint` are translation keys, not words: the settings form
     * resolves them, so a channel type describes its configuration once and
     * every locale renders it.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $required = true,
        public readonly string $hint = '',
        public readonly bool $isSecret = false,
    ) {
    }

    public static function secret(string $name, string $label, string $hint = ''): self
    {
        return new self($name, $label, 'password', true, $hint, true);
    }

    public static function url(string $name, string $label, string $hint = ''): self
    {
        return new self($name, $label, 'url', true, $hint);
    }

    public static function optional(string $name, string $label, string $hint = ''): self
    {
        return new self($name, $label, 'text', false, $hint);
    }
}
