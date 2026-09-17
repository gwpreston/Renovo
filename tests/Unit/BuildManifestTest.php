<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\BuildManifest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BuildManifestTest extends TestCase
{
    private string $root;

    private string $manifest;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/renovo-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->manifest = $this->root . '/manifest.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        @rmdir($this->root);
    }

    public function testResolvesALogicalNameToTheHashedFile(): void
    {
        $this->writeManifest([
            'assets/css/app.css' => ['file' => 'app-C84PQZax.css', 'isEntry' => true],
            'assets/js/app.js' => ['file' => 'app-COy3YFIQ.js', 'isEntry' => true],
        ]);

        $build = new BuildManifest($this->manifest);

        self::assertSame('/build/app-C84PQZax.css', $build->url('app.css'));
        self::assertSame('/build/app-COy3YFIQ.js', $build->url('app.js'));
    }

    public function testUsesTheGivenBaseUrl(): void
    {
        $this->writeManifest(['assets/css/app.css' => ['file' => 'app-abc.css', 'isEntry' => true]]);

        $build = new BuildManifest($this->manifest, '/static/');

        self::assertSame('/static/app-abc.css', $build->url('app.css'));
    }

    /**
     * The font files and the lazily-loaded chart chunk are in the manifest
     * too. Which chunks a bundler emits is its own decision, and a template
     * naming one would be a template depending on that decision.
     */
    public function testOnlyEntryPointsAreAddressable(): void
    {
        $this->writeManifest([
            'assets/js/app.js' => ['file' => 'app-abc.js', 'isEntry' => true],
            'node_modules/chart.js/dist/chart.js' => ['file' => 'chart-def.js', 'isDynamicEntry' => true],
            'node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2' => [
                'file' => 'inter-latin-wght-normal-xyz.woff2',
            ],
        ]);

        $build = new BuildManifest($this->manifest);

        $this->expectException(RuntimeException::class);
        $build->url('chart.js');
    }

    public function testAMissingManifestNamesTheCommandThatProducesIt(): void
    {
        $build = new BuildManifest($this->manifest);

        self::assertFalse($build->isBuilt());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/npm run build/');
        $build->url('app.css');
    }

    public function testAnUnknownNameListsWhatThereIs(): void
    {
        $this->writeManifest(['assets/css/app.css' => ['file' => 'app-abc.css', 'isEntry' => true]]);

        $build = new BuildManifest($this->manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/app\.css/');
        $build->url('print.css');
    }

    public function testInvalidJsonIsReportedAsSuch(): void
    {
        file_put_contents($this->manifest, '{ not json');

        $build = new BuildManifest($this->manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        $build->url('app.css');
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function writeManifest(array $entries): void
    {
        file_put_contents($this->manifest, json_encode($entries, JSON_THROW_ON_ERROR));
    }
}
