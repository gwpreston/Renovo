<?php

declare(strict_types=1);

namespace App\I18n;

use MessageFormatter;

/**
 * Turns a key into a sentence in the current locale.
 *
 * Messages are ICU MessageFormat, formatted by ext-intl. That matters for more
 * than tidiness: plural rules are a property of a language, not of English —
 * Polish has three forms and Arabic six — so a translation layer that decides
 * between singular and plural with `$n === 1` is correct in exactly the
 * languages that do not need it. Handing the message to ICU with named
 * arguments means each locale's own catalogue states its own rules.
 *
 * A message with no arguments is returned verbatim rather than run through the
 * formatter, so an apostrophe or a stray brace in a plain string cannot be
 * mistaken for ICU syntax.
 *
 * Lookup falls back: a regional locale to its language, then to the base. An
 * `fr_CA` catalogue may therefore hold only what Canadian French says
 * differently, and `en` is the one catalogue that must be complete.
 */
final class Translator
{
    public const BASE_LOCALE = 'en';

    public function __construct(
        private readonly CatalogLoader $catalogs,
        private readonly LocaleContext $context,
        /**
         * Whether a missing key is fatal. On under APP_ENV=test; off in
         * production, where a missing string must never take a page down.
         */
        private readonly bool $strict = false,
    ) {
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    public function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        $locale ??= $this->context->get();
        $message = $this->lookup($key, $locale);

        if ($message === null) {
            if ($this->strict) {
                throw MissingTranslationException::forKey($key, $locale);
            }

            return $key;
        }

        if ($parameters === []) {
            // A message with braces and nothing to put in them would print its
            // own ICU source to the page — a failure the completeness check
            // cannot see, because the key is present and correct. Strict mode
            // turns it into a failing test instead.
            if ($this->strict && str_contains($message, '{')) {
                throw MissingTranslationException::forUnsuppliedArguments($key, $locale, $message);
            }

            return $message;
        }

        $formatted = MessageFormatter::formatMessage($locale, $message, $parameters);

        if ($formatted === false) {
            if ($this->strict) {
                throw MissingTranslationException::forMalformedMessage($key, $locale, $message);
            }

            // A catalogue with a malformed ICU message would otherwise print
            // nothing at all. The unformatted message is a worse sentence than
            // the formatted one and a better one than an empty string.
            return $message;
        }

        return $formatted;
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->lookup($key, $locale ?? $this->context->get()) !== null;
    }

    /**
     * Every key under a prefix, in the current locale — how the small amount
     * of JavaScript in this application gets its strings.
     *
     * Reading from the same catalogue as the server is the point: a second,
     * JavaScript-only catalogue is one the completeness check does not see,
     * and it would drift the moment somebody added a string to it.
     *
     * @return array<string, string>
     */
    public function group(string $prefix, ?string $locale = null): array
    {
        $locale ??= $this->context->get();
        $messages = [];

        foreach ($this->chain($locale) as $candidate) {
            foreach ($this->catalogs->load($candidate) as $key => $message) {
                if (str_starts_with($key, $prefix) && !array_key_exists($key, $messages)) {
                    $messages[$key] = $message;
                }
            }
        }

        ksort($messages);

        return $messages;
    }

    private function lookup(string $key, string $locale): ?string
    {
        foreach ($this->chain($locale) as $candidate) {
            $catalog = $this->catalogs->load($candidate);

            if (isset($catalog[$key])) {
                return $catalog[$key];
            }
        }

        return null;
    }

    /**
     * `fr_CA` → `fr_CA`, `fr`, `en`.
     *
     * @return list<string>
     */
    private function chain(string $locale): array
    {
        $chain = [$locale];

        $underscore = strpos($locale, '_');
        if ($underscore !== false) {
            $chain[] = substr($locale, 0, $underscore);
        }

        if (!in_array(self::BASE_LOCALE, $chain, true)) {
            $chain[] = self::BASE_LOCALE;
        }

        return $chain;
    }
}
