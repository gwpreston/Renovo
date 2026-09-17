<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\ExternalAssetScanner;
use PHPUnit\Framework\TestCase;

/**
 * The offline rule's own test.
 *
 * Two halves: that the scanner finds what it is for, on a tree built here; and
 * that this repository is clean, which is the assertion `assets:offline-check`
 * makes in CI and the reason the scanner exists.
 */
final class ExternalAssetScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/renovo-offline-' . bin2hex(random_bytes(6));

        foreach (['templates', 'assets/css', 'public/assets/logos', 'public/build'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testFindsAStylesheetLinkedFromACdn(): void
    {
        $this->write('templates/layout.twig', <<<'TWIG'
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pico.css">
            TWIG);

        $found = (new ExternalAssetScanner($this->root))->scan();

        self::assertCount(1, $found);
        self::assertSame('templates/layout.twig', $found[0]->file);
        self::assertSame(1, $found[0]->line);
        self::assertSame('https://cdn.jsdelivr.net/npm/pico.css', $found[0]->url);
    }

    public function testFindsAFontImportedByADependencyIntoTheBuiltOutput(): void
    {
        // The failure this check exists for: our own sources are clean, and a
        // package we installed put a CDN in the stylesheet it compiled into.
        $this->write('assets/css/app.css', "@import 'inter';\n");
        $this->write('public/build/app-abc123.css', <<<'CSS'
            @font-face {
              font-family: 'Inter';
              src: url(https://fonts.gstatic.com/s/inter/v13/inter.woff2);
            }
            CSS);

        $found = (new ExternalAssetScanner($this->root))->scan();

        self::assertCount(1, $found);
        self::assertSame('public/build/app-abc123.css', $found[0]->file);
        self::assertSame(3, $found[0]->line);
    }

    public function testReportsEveryReferenceWithItsLine(): void
    {
        $this->write('templates/page.twig', <<<'TWIG'
            <p>Fine.</p>
            <script src="https://cdn.example.com/a.js"></script>
            <p>Also fine.</p>
            <script src="http://cdn.example.com/b.js"></script>
            TWIG);

        $found = (new ExternalAssetScanner($this->root))->scan();

        self::assertSame([2, 4], array_map(static fn ($r): int => $r->line, $found));
    }

    /** `xmlns` declares what an element is; nothing fetches it. */
    public function testAllowsTheSvgNamespace(): void
    {
        $this->write('templates/icon.twig', <<<'TWIG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"></svg>
            TWIG);

        self::assertSame([], (new ExternalAssetScanner($this->root))->scan());
    }

    public function testIgnoresARelativeOrRootRelativeUrl(): void
    {
        $this->write('templates/layout.twig', <<<'TWIG'
            <link rel="stylesheet" href="/build/app-abc123.css">
            <script src="/assets/htmx.min.js"></script>
            <img src="../logo.png">
            TWIG);

        self::assertSame([], (new ExternalAssetScanner($this->root))->scan());
    }

    /**
     * A sourcemap carries its dependencies' original source, comments and all,
     * so scanning one would report every library's documentation link. What
     * can actually cause a fetch is the sourceMappingURL comment, and that is
     * in the .js file, which is scanned.
     */
    public function testIgnoresSourcemapsAndUploadedFiles(): void
    {
        $this->write('public/build/app-abc123.js.map', '{"sources":["https://example.com/vendor.js"]}');
        $this->write('public/assets/logos/example.com.svg', '<svg><image href="https://cdn.example.com/x.png"/></svg>');

        self::assertSame([], (new ExternalAssetScanner($this->root))->scan());
    }

    public function testAMissingDirectoryIsNotAFailure(): void
    {
        $this->remove($this->root . '/public/build');

        self::assertSame([], (new ExternalAssetScanner($this->root))->scan());
    }

    /**
     * The assertion CI makes. It is here as well as in the console command so
     * that a CDN link added to a template fails the ordinary test run too,
     * rather than waiting for the job that builds the assets.
     */
    public function testThisRepositoryReferencesNoThirdPartyHost(): void
    {
        $found = (new ExternalAssetScanner(dirname(__DIR__, 2)))->scan();

        self::assertSame(
            [],
            array_map(static fn ($r): string => $r->describe(), $found),
            'Nothing the browser loads may come from a third-party host — see ExternalAssetScanner.',
        );
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $contents);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
