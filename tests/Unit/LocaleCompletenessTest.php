<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\I18n\CatalogChecker;
use App\I18n\CatalogLoader;
use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * The check CI runs: every locale against the base catalogue, both directions.
 *
 * The last test in this file is the one that matters day to day — it points
 * the checker at the catalogues this application actually ships, so a locale
 * that has drifted fails here as well as in CI.
 */
final class LocaleCompletenessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/renovo-locales-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testAnIncompleteLocaleFailsTheCheck(): void
    {
        $this->writeCatalog('en', ['a' => 'A', 'b' => 'B', 'c' => 'C']);
        $this->writeCatalog('fr', ['a' => 'A in French']);

        $report = $this->checker()->checkLocale('fr');

        self::assertFalse($report->isComplete());
        self::assertSame(['b', 'c'], $report->missing);
        self::assertSame([], $report->extra);
        self::assertSame(1, $report->translated());
        self::assertSame(3, $report->total);
    }

    /**
     * The direction nobody looks for. A key the base no longer has is either a
     * message nothing renders or — worse — a typo, which means the page that
     * believes it is translated is quietly falling back to English.
     */
    public function testAKeyTheBaseDoesNotHaveAlsoFailsTheCheck(): void
    {
        $this->writeCatalog('en', ['a' => 'A']);
        $this->writeCatalog('fr', ['a' => 'A in French', 'nav.dashbord' => 'Tableau de bord']);

        $report = $this->checker()->checkLocale('fr');

        self::assertFalse($report->isComplete());
        self::assertSame([], $report->missing);
        self::assertSame(['nav.dashbord'], $report->extra);
    }

    public function testAnEmptyMessageCountsAsUntranslated(): void
    {
        $this->writeCatalog('en', ['a' => 'A', 'b' => 'B']);
        $this->writeCatalog('fr', ['a' => 'A in French', 'b' => '   ']);

        self::assertSame(['b'], $this->checker()->checkLocale('fr')->missing);
    }

    public function testACompleteLocalePasses(): void
    {
        $this->writeCatalog('en', ['a' => 'A', 'b' => 'B']);
        $this->writeCatalog('fr', ['a' => 'A in French', 'b' => 'B in French']);

        self::assertTrue($this->checker()->checkLocale('fr')->isComplete());
    }

    public function testTheBaseLocaleIsNotComparedWithItself(): void
    {
        $this->writeCatalog('en', ['a' => 'A']);
        $this->writeCatalog('fr', ['a' => 'A in French']);

        $reports = $this->checker()->check();

        self::assertCount(1, $reports);
        self::assertSame('fr', $reports[0]->locale);
    }

    /**
     * No message carries an escape sequence as literal text.
     *
     * A catalogue entry in single quotes keeps `\n` as a backslash and an "n",
     * so a mail body written that way arrives as one long line with the
     * escapes printed in it. Messages that need a line break are written in
     * double quotes; this catches one that is not, in any shipped locale.
     */
    public function testNoMessageHoldsALiteralEscapeSequence(): void
    {
        $loader = new CatalogLoader(dirname(__DIR__, 2) . '/translations');

        foreach ($loader->availableLocales() as $locale) {
            foreach ($loader->load($locale) as $key => $message) {
                self::assertDoesNotMatchRegularExpression(
                    '/\\\\[nrt]/',
                    $message,
                    sprintf('"%s" in locale "%s" holds a literal escape; write it in double quotes.', $key, $locale),
                );
            }
        }
    }

    /**
     * The shipped catalogues, checked here as well as in CI so that a locale
     * cannot drift between pushes.
     */
    public function testEveryShippedLocaleIsComplete(): void
    {
        $loader = new CatalogLoader(dirname(__DIR__, 2) . '/translations');

        self::assertContains(
            Translator::BASE_LOCALE,
            $loader->availableLocales(),
            'The base catalogue must exist.',
        );

        self::assertNotSame([], $loader->load(Translator::BASE_LOCALE), 'The base catalogue must not be empty.');

        foreach ((new CatalogChecker($loader))->check() as $report) {
            self::assertTrue($report->isComplete(), sprintf(
                'Locale "%s" has drifted from the base catalogue: %d missing (%s), %d extra (%s).',
                $report->locale,
                count($report->missing),
                implode(', ', array_slice($report->missing, 0, 5)),
                count($report->extra),
                implode(', ', array_slice($report->extra, 0, 5)),
            ));
        }
    }

    private function checker(): CatalogChecker
    {
        return new CatalogChecker(new CatalogLoader($this->directory));
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
