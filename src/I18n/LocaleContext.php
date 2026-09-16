<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * The locale the current request — or the current recipient — is being served
 * in.
 *
 * One mutable object with a small number of writers, on the same pattern as
 * RequestContextHolder: the alternative was passing a locale into every
 * service method that might one day produce a sentence, which is how a
 * translation layer ends up covering whatever code remembered to ask.
 *
 * The writers are LocaleMiddleware, which sets it from the signed-in user's
 * preference, and the notification dispatcher, which sets it per recipient
 * before building their message. Everything else reads. It defaults to the
 * instance's configured locale so that a console run is never without one.
 */
final class LocaleContext
{
    private string $locale;

    public function __construct(private readonly string $default)
    {
        $this->locale = $default;
    }

    public function set(string $locale): void
    {
        $this->locale = $locale;
    }

    public function get(): string
    {
        return $this->locale;
    }

    public function default(): string
    {
        return $this->default;
    }

    /**
     * The same locale as a BCP 47 tag, for `<html lang>` and `Content-Language`.
     * ICU writes `en_GB`; HTML wants `en-GB`.
     */
    public function tag(): string
    {
        return str_replace('_', '-', $this->locale);
    }

    /**
     * Run a callable with a different locale in force, then restore whatever
     * was there.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function using(string $locale, callable $callback): mixed
    {
        $previous = $this->locale;
        $this->locale = $locale;

        try {
            return $callback();
        } finally {
            $this->locale = $previous;
        }
    }
}
