<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Compares every locale against the base catalogue.
 *
 * Both directions are reported and both fail the check. A missing key is the
 * obvious drift — a string added to `en` and never translated. An extra key is
 * the one that would otherwise go unnoticed for years: it is a message that
 * nothing renders any more, or a typo in a key that means the locale is
 * silently falling back to English on a page somebody believes is translated.
 *
 * A key present but empty counts as missing. A translator working through a
 * file leaves blanks behind, and a blank is not a translation.
 *
 * This deliberately does not scan the source for keys. That would need an
 * extractor, an extractor is fragile against any key that is composed rather
 * than written out, and a fragile extractor in CI trains people to ignore it.
 * The gap it would cover — a key used by a template and present in no
 * catalogue — is covered instead by the translator throwing under APP_ENV=test,
 * which the page-rendering tests exercise for real.
 */
final class CatalogChecker
{
    public function __construct(private readonly CatalogLoader $catalogs)
    {
    }

    /**
     * @return list<CatalogReport>
     */
    public function check(): array
    {
        $reports = [];

        foreach ($this->catalogs->availableLocales() as $locale) {
            if ($locale === Translator::BASE_LOCALE) {
                continue;
            }

            $reports[] = $this->checkLocale($locale);
        }

        return $reports;
    }

    public function checkLocale(string $locale): CatalogReport
    {
        $base = $this->catalogs->load(Translator::BASE_LOCALE);
        $target = $this->catalogs->load($locale);

        $missing = [];
        foreach ($base as $key => $message) {
            if (!isset($target[$key]) || trim($target[$key]) === '') {
                $missing[] = $key;
            }
        }

        $extra = [];
        foreach ($target as $key => $message) {
            if (!array_key_exists($key, $base)) {
                $extra[] = $key;
            }
        }

        sort($missing);
        sort($extra);

        return new CatalogReport($locale, $missing, $extra, count($base));
    }
}
