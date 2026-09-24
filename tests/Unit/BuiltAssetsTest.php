<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\BuildManifest;
use PHPUnit\Framework\TestCase;

/**
 * What the build actually produced.
 *
 * BuildManifestTest checks the reader against a manifest written by hand; this
 * checks the real output of `npm run build` — that the entries the layout asks
 * for are there, that the vendored fonts resolve to local files that exist,
 * that the icon sprite is built and indexed, and that Chart.js is a chunk of
 * its own rather than weight on every page.
 *
 * Skipped when there is no build, so `vendor/bin/phpunit` still runs on a
 * checkout where `npm install` has not been. CI runs the build first, in both
 * the asset job and the test job, so there it is never skipped.
 */
final class BuiltAssetsTest extends TestCase
{
    private const BASE_URL = '/build/';

    private string $buildPath;

    private BuildManifest $build;

    protected function setUp(): void
    {
        $this->buildPath = dirname(__DIR__, 2) . '/public/build';
        $this->build = new BuildManifest($this->buildPath . '/manifest.json', self::BASE_URL);

        if (!$this->build->isBuilt()) {
            self::markTestSkipped('No asset build present. Run "npm install && npm run build".');
        }
    }

    /**
     * The two names templates/layout.twig passes to `bundle()`. If this fails,
     * every page in the application is a 500.
     *
     * @return iterable<array{string, string}>
     */
    public static function entries(): iterable
    {
        yield 'stylesheet' => ['app.css', 'css'];
        yield 'script' => ['app.js', 'js'];
    }

    /**
     * @dataProvider entries
     */
    public function testTheLayoutsEntriesResolveToFilesThatExist(string $name, string $extension): void
    {
        $url = $this->build->url($name);

        self::assertStringStartsWith(self::BASE_URL, $url, 'The URL must match the nginx location block.');
        self::assertMatchesRegularExpression(
            '~^' . preg_quote(self::BASE_URL, '~') . 'app-[A-Za-z0-9_-]+\.' . $extension . '$~',
            $url,
            'The filename must carry a content hash, or an upgrade is cached away.',
        );
        self::assertFileExists($this->pathFor($url));
    }

    /**
     * The whole point of vendoring the fonts: every @font-face in the compiled
     * stylesheet points at a file this instance serves. A CDN URL here would
     * be a page that renders in fallback type on a network with no route out —
     * and it would have arrived through a dependency, not through a template.
     */
    public function testTheVendoredFontsAreServedFromThisInstance(): void
    {
        $css = (string) file_get_contents($this->pathFor($this->build->url('app.css')));

        foreach (['Plus Jakarta Sans Variable', 'JetBrains Mono Variable'] as $family) {
            self::assertMatchesRegularExpression(
                '~@font-face\{[^}]*font-family:\s*["\']?' . preg_quote($family, '~') . '~',
                $css,
                sprintf('No @font-face rule for %s.', $family),
            );
        }

        preg_match_all('~src:\s*url\(([^)]+)\)~i', $css, $matches);
        self::assertNotEmpty($matches[1], 'No @font-face src found in the compiled stylesheet.');

        foreach ($matches[1] as $url) {
            $url = trim($url, '"\'');

            self::assertStringStartsWith(self::BASE_URL, $url, sprintf('Font "%s" is not served locally.', $url));
            self::assertFileExists($this->pathFor($url));
        }
    }

    /** Shipping the .woff2 without the licence beside it would not honour it. */
    public function testEachFontsLicenceIsVendoredAlongsideIt(): void
    {
        foreach (['plus-jakarta-sans-OFL.txt', 'jetbrains-mono-OFL.txt'] as $file) {
            $licence = $this->buildPath . '/' . $file;

            self::assertFileExists($licence);
            self::assertStringContainsString(
                'SIL OPEN FONT LICENSE',
                strtoupper((string) file_get_contents($licence)),
            );
        }
    }

    /**
     * The manifest records every file the build produced, the fonts and the
     * sprite among them — the manifest is what an operator checks to see what
     * a page can load, and the sprite is loaded by every signed-in page.
     */
    public function testTheManifestListsTheFontsAndTheSprite(): void
    {
        $manifest = (string) file_get_contents($this->buildPath . '/manifest.json');

        self::assertStringContainsString('plus-jakarta-sans', $manifest);
        self::assertStringContainsString('jetbrains-mono', $manifest);
        self::assertStringContainsString('"assets/theme/sprite.svg"', $manifest);
    }

    /**
     * The sprite exists under its hashed name, is indexed beside it, and holds
     * a symbol for every name the index promises — so `icon()` saying "yes,
     * that one exists" is a statement about the file the browser will fetch.
     */
    public function testTheIconSpriteHoldsEveryIndexedIcon(): void
    {
        $index = json_decode((string) file_get_contents($this->buildPath . '/icons.json'), true);
        self::assertIsArray($index);
        self::assertIsString($index['sprite'] ?? null);
        self::assertIsArray($index['names'] ?? null);

        $sprite = (string) file_get_contents($this->buildPath . '/' . $index['sprite']);
        self::assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $sprite));

        foreach ($index['names'] as $name) {
            self::assertStringContainsString(sprintf('<symbol id="%s"', $name), $sprite);
        }
    }

    /**
     * Chart.js is around 200 KB, and most pages here show a table. The dynamic
     * import in assets/js/charts.js is what keeps it off them, and this is
     * what notices if someone turns it into a static one.
     */
    public function testChartJsIsASeparateChunkAndNotInTheEntryBundle(): void
    {
        $entry = (string) file_get_contents($this->pathFor($this->build->url('app.js')));

        self::assertLessThan(
            100_000,
            strlen($entry),
            'The entry bundle is large enough to suggest Chart.js was statically imported into it.',
        );

        $chunks = glob($this->buildPath . '/chart-*.js') ?: [];
        self::assertNotEmpty($chunks, 'No lazily-loaded chart chunk was emitted.');
    }

    /** The URL as the page sees it, resolved back to a path on disk. */
    private function pathFor(string $url): string
    {
        return $this->buildPath . '/' . substr($url, strlen(self::BASE_URL));
    }
}
