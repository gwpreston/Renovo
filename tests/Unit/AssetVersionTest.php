<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\AssetVersion;
use PHPUnit\Framework\TestCase;

final class AssetVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/renovo-assets-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/assets', 0o775, true);
        file_put_contents($this->root . '/assets/app.css', 'body { color: red; }');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/assets/app.css');
        @rmdir($this->root . '/assets');
        @rmdir($this->root);
    }

    public function testAppendsTheModificationTimeAsAQueryParameter(): void
    {
        $assets = new AssetVersion($this->root);

        $url = $assets->url('/assets/app.css');

        self::assertStringStartsWith('/assets/app.css?v=', $url);
        self::assertSame(
            dechex((int) filemtime($this->root . '/assets/app.css')),
            substr($url, strlen('/assets/app.css?v=')),
        );
    }

    public function testTheSameFileAlwaysGetsTheSameUrl(): void
    {
        $assets = new AssetVersion($this->root);

        self::assertSame($assets->url('/assets/app.css'), $assets->url('/assets/app.css'));
        // And a second instance agrees, so two workers do not serve two
        // different URLs for one file and halve the cache hit rate.
        self::assertSame(
            $assets->url('/assets/app.css'),
            (new AssetVersion($this->root))->url('/assets/app.css'),
        );
    }

    public function testTheUrlChangesWhenTheFileDoes(): void
    {
        $before = (new AssetVersion($this->root))->url('/assets/app.css');

        // The whole point: an upgrade must not leave a browser on the previous
        // stylesheet until its cache entry expires.
        touch($this->root . '/assets/app.css', time() + 60);
        clearstatcache(true, $this->root . '/assets/app.css');

        self::assertNotSame($before, (new AssetVersion($this->root))->url('/assets/app.css'));
    }

    public function testAFragmentStaysAtTheEnd(): void
    {
        $assets = new AssetVersion($this->root);

        self::assertMatchesRegularExpression(
            '#^/assets/app\\.css\\?v=[0-9a-f]+\\#top$#',
            $assets->url('/assets/app.css#top'),
        );
    }

    public function testAMissingFileIsReturnedUntouched(): void
    {
        $assets = new AssetVersion($this->root);

        // A missing stylesheet is visible on its own and does not need the
        // page to fail rendering on top of it.
        self::assertSame('/assets/gone.css', $assets->url('/assets/gone.css'));
        self::assertSame('/assets', $assets->url('/assets'));
    }

    public function testAPathThatAlreadyHasAQueryStringKeepsIt(): void
    {
        $assets = new AssetVersion($this->root);

        self::assertMatchesRegularExpression(
            '#^/assets/app\\.css\\?v=[0-9a-f]+&theme=dark$#',
            $assets->url('/assets/app.css?theme=dark'),
        );
    }
}
