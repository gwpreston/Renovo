<?php

declare(strict_types=1);

namespace App\I18n;

use Locale as IntlLocale;

/**
 * Which locales this instance can serve, and which one a given request gets.
 *
 * The list is whatever catalogues are on disk, so adding a language is adding
 * `translations/<locale>.php` and nothing else — no registry to update, no
 * enum to extend, and the completeness check picks the new file up on its next
 * run.
 */
final class Locales
{
    public function __construct(
        private readonly CatalogLoader $catalogs,
        private readonly string $default,
    ) {
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return $this->catalogs->availableLocales();
    }

    public function isAvailable(string $locale): bool
    {
        return in_array($locale, $this->available(), true);
    }

    /**
     * The locales a user may choose, each named in its own language — a
     * speaker of a language recognises its name in that language, and not
     * necessarily in this instance's.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->available() as $locale) {
            $name = IntlLocale::getDisplayName($locale, $locale);
            $choices[$locale] = $name === '' || $name === false
                ? $locale
                : mb_convert_case(mb_substr($name, 0, 1), MB_CASE_UPPER) . mb_substr($name, 1);
        }

        return $choices;
    }

    /**
     * The locale for a stored preference: the user's if it is one this
     * instance still has a catalogue for, and the instance default otherwise.
     *
     * A preference that no longer resolves is not an error. An operator may
     * remove a locale, and an account that had chosen it should fall back to
     * the instance's language rather than to a page of untranslated keys.
     */
    public function resolve(?string $preferred): string
    {
        if ($preferred !== null && $preferred !== '' && $this->isAvailable($preferred)) {
            return $preferred;
        }

        return $this->default;
    }

    /**
     * The best available locale for an `Accept-Language` header — what an
     * anonymous visitor on the sign-in page gets, since there is no account to
     * ask.
     */
    public function negotiate(string $acceptLanguage): string
    {
        foreach ($this->parseHeader($acceptLanguage) as $candidate) {
            if ($this->isAvailable($candidate)) {
                return $candidate;
            }

            $language = str_contains($candidate, '_') ? strstr($candidate, '_', true) : null;
            if (is_string($language) && $this->isAvailable($language)) {
                return $language;
            }
        }

        return $this->default;
    }

    /**
     * Header tags in descending q order, normalised to ICU spelling.
     *
     * @return list<string>
     */
    private function parseHeader(string $header): array
    {
        $weighted = [];

        foreach (explode(',', $header) as $index => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $pieces = explode(';', $part);
            $tag = trim($pieces[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }

            if ($quality <= 0.0) {
                continue;
            }

            // Ordered by quality, and by position within the header for ties:
            // "en, fr" means English first even though both are q=1.
            $weighted[] = ['tag' => str_replace('-', '_', $tag), 'q' => $quality, 'at' => $index];
        }

        usort(
            $weighted,
            static fn (array $a, array $b): int => $b['q'] <=> $a['q'] ?: $a['at'] <=> $b['at'],
        );

        return array_values(array_map(static fn (array $entry): string => (string) $entry['tag'], $weighted));
    }
}
