<?php

declare(strict_types=1);

namespace App\I18n;

use RuntimeException;

/**
 * Raised when a key has no message in any catalogue — but only where that is
 * worth stopping for.
 *
 * In production a missing key prints the key. A half-translated page is a
 * blemish; a page that will not render at all because somebody added a string
 * and forgot the catalogue is an outage. Under APP_ENV=test the same situation
 * throws, which is what turns every page-rendering test into a check that the
 * strings it reaches actually exist.
 */
final class MissingTranslationException extends RuntimeException
{
    public static function forKey(string $key, string $locale): self
    {
        return new self(sprintf(
            'No translation for "%s" in locale "%s" or the base catalogue. '
            . 'Add it to translations/%s.php.',
            $key,
            $locale,
            Translator::BASE_LOCALE,
        ));
    }

    /**
     * A message that takes arguments, called without any. The key exists, so
     * the completeness check passes and the page prints ICU source.
     */
    public static function forUnsuppliedArguments(string $key, string $locale, string $message): self
    {
        return new self(sprintf(
            'The message for "%s" in locale "%s" takes arguments and none were given: %s',
            $key,
            $locale,
            $message,
        ));
    }

    public static function forMalformedMessage(string $key, string $locale, string $message): self
    {
        return new self(sprintf(
            'The message for "%s" in locale "%s" could not be formatted — check its ICU syntax: %s',
            $key,
            $locale,
            $message,
        ));
    }
}
