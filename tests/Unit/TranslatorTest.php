<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\I18n\CatalogLoader;
use App\I18n\LocaleContext;
use App\I18n\Locales;
use App\I18n\MissingTranslationException;
use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * The translation layer itself, against catalogues written for the test rather
 * than against the shipped ones — so that adding a string to `en` can never
 * break a test about fallback or plural rules.
 */
final class TranslatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/renovo-i18n-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testReturnsTheMessageForTheCurrentLocale(): void
    {
        $this->writeCatalog('en', ['nav.dashboard' => 'Dashboard']);
        $this->writeCatalog('fr', ['nav.dashboard' => 'Tableau de bord']);

        $context = new LocaleContext('fr');

        self::assertSame('Tableau de bord', $this->translator($context)->trans('nav.dashboard'));
    }

    public function testFallsBackThroughTheLanguageToTheBase(): void
    {
        $this->writeCatalog('en', ['a' => 'A', 'b' => 'B', 'c' => 'C']);
        $this->writeCatalog('fr', ['b' => 'B in French', 'c' => 'C in French']);
        $this->writeCatalog('fr_CA', ['c' => 'C in Quebec']);

        $translator = $this->translator(new LocaleContext('fr_CA'));

        self::assertSame('C in Quebec', $translator->trans('c'), 'The regional catalogue wins.');
        self::assertSame('B in French', $translator->trans('b'), 'Then the language.');
        self::assertSame('A', $translator->trans('a'), 'Then the base.');
    }

    /**
     * The reason ICU is here rather than an `=== 1` check: Polish has three
     * plural forms and picks between them on rules English does not have. A
     * translation layer that decided the form itself would be correct only in
     * the languages that did not need it.
     */
    public function testPluralsFollowTheRulesOfTheLocaleNotOfEnglish(): void
    {
        $this->writeCatalog('en', [
            'notice.days' => '{count, plural, one {# day} other {# days}}',
        ]);
        $this->writeCatalog('pl', [
            'notice.days' => '{count, plural, one {# dzień} few {# dni} many {# dni} other {# dnia}}',
        ]);

        $english = $this->translator(new LocaleContext('en'));
        self::assertSame('1 day', $english->trans('notice.days', ['count' => 1]));
        self::assertSame('3 days', $english->trans('notice.days', ['count' => 3]));

        $polish = $this->translator(new LocaleContext('pl'));
        self::assertSame('1 dzień', $polish->trans('notice.days', ['count' => 1]));
        self::assertSame('3 dni', $polish->trans('notice.days', ['count' => 3]));
        self::assertSame('5 dni', $polish->trans('notice.days', ['count' => 5]));
    }

    public function testAMessageWithNoArgumentsIsNotRunThroughIcu(): void
    {
        // An apostrophe is ICU's quoting character in some positions. A plain
        // string must survive one untouched.
        $this->writeCatalog('en', ['flash.saved' => "That didn't work"]);

        self::assertSame(
            "That didn't work",
            $this->translator(new LocaleContext('en'))->trans('flash.saved'),
        );
    }

    public function testAMissingKeyPrintsItselfWhenNotStrict(): void
    {
        $this->writeCatalog('en', []);

        self::assertSame(
            'nav.nowhere',
            $this->translator(new LocaleContext('en'))->trans('nav.nowhere'),
        );
    }

    public function testAMissingKeyIsFatalUnderStrictMode(): void
    {
        $this->writeCatalog('en', []);

        $this->expectException(MissingTranslationException::class);

        $this->translator(new LocaleContext('en'), strict: true)->trans('nav.nowhere');
    }

    /**
     * The failure the completeness check cannot see: the key is there, the
     * message takes an argument, and the caller forgot it. Without this the
     * page prints ICU source.
     */
    public function testAMessageNeedingArgumentsIsFatalUnderStrictModeWhenGivenNone(): void
    {
        $this->writeCatalog('en', ['notice.days' => '{count, plural, one {# day} other {# days}}']);

        $this->expectException(MissingTranslationException::class);

        $this->translator(new LocaleContext('en'), strict: true)->trans('notice.days');
    }

    public function testAMalformedMessageIsFatalUnderStrictMode(): void
    {
        $this->writeCatalog('en', ['broken' => '{count, plural, one {# day}']);

        $this->expectException(MissingTranslationException::class);

        $this->translator(new LocaleContext('en'), strict: true)->trans('broken', ['count' => 2]);
    }

    public function testGroupCollectsAPrefixWithTheFallbackApplied(): void
    {
        $this->writeCatalog('en', ['js.close' => 'Close', 'js.save' => 'Save', 'nav.home' => 'Home']);
        $this->writeCatalog('fr', ['js.close' => 'Fermer']);

        $group = $this->translator(new LocaleContext('fr'))->group('js.');

        self::assertSame(['js.close' => 'Fermer', 'js.save' => 'Save'], $group);
    }

    public function testTheLocaleContextRestoresItselfAfterATemporarySwitch(): void
    {
        $context = new LocaleContext('en');

        $inner = $context->using('fr', static fn (): string => $context->get());

        self::assertSame('fr', $inner);
        self::assertSame('en', $context->get(), 'The previous locale comes back.');
    }

    public function testTheContextTagIsBcp47(): void
    {
        self::assertSame('en-GB', (new LocaleContext('en_GB'))->tag());
    }

    public function testAcceptLanguageNegotiationPrefersTheHighestQuality(): void
    {
        $this->writeCatalog('en', []);
        $this->writeCatalog('de', []);
        $this->writeCatalog('fr', []);

        $locales = new Locales(new CatalogLoader($this->directory), 'en');

        self::assertSame('fr', $locales->negotiate('de;q=0.4, fr;q=0.9'));
        self::assertSame('de', $locales->negotiate('de-AT, fr;q=0.2'), 'A region falls back to its language.');
        self::assertSame('en', $locales->negotiate('ja, ko;q=0.5'), 'Nothing available means the default.');
        self::assertSame('en', $locales->negotiate(''));
    }

    public function testAStoredPreferenceIsOnlyHonouredWhileItsCatalogueExists(): void
    {
        $this->writeCatalog('en', []);
        $this->writeCatalog('de', []);

        $locales = new Locales(new CatalogLoader($this->directory), 'en_GB');

        self::assertSame('de', $locales->resolve('de'));
        self::assertSame('en_GB', $locales->resolve('sv'), 'A locale that was removed falls back.');
        self::assertSame('en_GB', $locales->resolve(''));
        self::assertSame('en_GB', $locales->resolve(null));
    }

    /**
     * A locale name is a locale name. Anything else must not become a path.
     */
    public function testACatalogueNameCannotTraverseTheFilesystem(): void
    {
        $this->writeCatalog('en', ['a' => 'A']);

        $loader = new CatalogLoader($this->directory);

        self::assertSame([], $loader->load('../../composer'));
        self::assertFalse($loader->has('../../composer'));
    }

    private function translator(LocaleContext $context, bool $strict = false): Translator
    {
        return new Translator(new CatalogLoader($this->directory), $context, $strict);
    }

    /**
     * @param array<string, string> $messages
     */
    private function writeCatalog(string $locale, array $messages): void
    {
        file_put_contents(
            $this->directory . '/' . $locale . '.php',
            "<?php\n\nreturn " . var_export($messages, true) . ";\n",
        );
    }
}
