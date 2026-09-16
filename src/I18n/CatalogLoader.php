<?php

declare(strict_types=1);

namespace App\I18n;

use RuntimeException;

/**
 * Reads the translation catalogues off disk.
 *
 * A catalogue is a PHP file returning a flat `key => message` array — flat
 * deliberately, because the completeness check is a set difference over keys
 * and a nested structure would turn that into a tree walk for no gain. PHP
 * rather than JSON or YAML because it needs no parser, opcache keeps it in
 * memory between requests, and a catalogue can carry comments explaining a
 * string's context to whoever translates it next.
 */
final class CatalogLoader
{
    /** @var array<string, array<string, string>> */
    private array $loaded = [];

    /** @var list<string>|null */
    private ?array $available = null;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return array<string, string>
     */
    public function load(string $locale): array
    {
        if (array_key_exists($locale, $this->loaded)) {
            return $this->loaded[$locale];
        }

        $path = $this->path($locale);

        if (!is_file($path)) {
            return $this->loaded[$locale] = [];
        }

        /** @var mixed $catalog */
        $catalog = require $path;

        if (!is_array($catalog)) {
            throw new RuntimeException(sprintf('Translation catalogue "%s" must return an array.', $path));
        }

        $flat = [];
        foreach ($catalog as $key => $message) {
            if (!is_string($key) || !is_string($message)) {
                throw new RuntimeException(sprintf(
                    'Translation catalogue "%s" must map string keys to string messages.',
                    $path,
                ));
            }

            $flat[$key] = $message;
        }

        return $this->loaded[$locale] = $flat;
    }

    public function has(string $locale): bool
    {
        return is_file($this->path($locale));
    }

    /**
     * Every locale with a catalogue, sorted, the base first.
     *
     * The list is the filesystem's, not a hardcoded array: adding a locale is
     * adding a file, and nothing else has to be told about it.
     *
     * @return list<string>
     */
    public function availableLocales(): array
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $locales = [];
        foreach (glob(rtrim($this->directory, '/') . '/*.php') ?: [] as $file) {
            $locale = basename($file, '.php');
            if (preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/', $locale) === 1) {
                $locales[] = $locale;
            }
        }

        sort($locales);

        usort($locales, static fn (string $a, string $b): int => match (true) {
            $a === Translator::BASE_LOCALE => -1,
            $b === Translator::BASE_LOCALE => 1,
            default => strcmp($a, $b),
        });

        return $this->available = $locales;
    }

    private function path(string $locale): string
    {
        // The locale never reaches the filesystem unfiltered: a catalogue name
        // is a locale code and nothing else, so a value arriving from a cookie
        // or a query string cannot become a path.
        if (preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
            return '';
        }

        return rtrim($this->directory, '/') . '/' . $locale . '.php';
    }
}
