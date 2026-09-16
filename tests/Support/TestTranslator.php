<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\I18n\CatalogLoader;
use App\I18n\LocaleContext;
use App\I18n\Translator;

/**
 * A translator over the catalogues this application actually ships, in strict
 * mode.
 *
 * Strict on purpose: a test that renders a message is also a test that the
 * message exists, and using the real catalogue rather than a fixture means a
 * key deleted from `en` fails here rather than in somebody's inbox.
 */
final class TestTranslator
{
    public static function create(string $locale = 'en_GB'): Translator
    {
        return new Translator(
            new CatalogLoader(dirname(__DIR__, 2) . '/translations'),
            new LocaleContext($locale),
            strict: true,
        );
    }
}
