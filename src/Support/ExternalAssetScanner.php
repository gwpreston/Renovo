<?php

declare(strict_types=1);

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The offline rule, enforced.
 *
 * Nothing the browser loads may come from a third-party host: no font CDN, no
 * chart CDN, no stylesheet pulled from a package's own delivery network. An
 * instance is expected to run on a LAN or behind a VPN with no route out at
 * all, so a page that quietly depends on a CDN is a page that breaks in
 * exactly the deployment this application is for. Build time may use the
 * network — npm and Composer both do — but run time may not.
 *
 * That is a rule review forgets, so it is checked instead: this scans the
 * templates, the build's sources and the build's *output* for an absolute
 * http(s) URL and reports every one it finds. The output matters as much as
 * the sources — a dependency that hardcodes a CDN in its own @font-face would
 * sail past a check that only read the files we wrote.
 *
 * Called by `bin/console assets:offline-check` (which CI runs) and by
 * ExternalAssetScannerTest, so there is one implementation and no second
 * opinion.
 */
final class ExternalAssetScanner
{
    /**
     * Where to look, relative to the repository root. A directory that is not
     * there is skipped: `public/build` does not exist until the build has run,
     * and the scan is not the thing that should complain about that.
     *
     * @var list<string>
     */
    public const DIRECTORIES = [
        'templates',
        'assets',
        'public/assets',
        'public/build',
    ];

    /**
     * What is read. Anything else in these directories — an uploaded logo, a
     * font file, an image — is either binary or cannot name a URL the browser
     * would follow.
     *
     * `.map` is deliberately absent. A sourcemap embeds its dependencies'
     * original source, comments and all, so every library's documentation link
     * would be reported; and a URL inside a sourcemap cannot cause a fetch.
     * What can is the `sourceMappingURL` comment that points at it, and that
     * lives in the .js or .css file, which is scanned.
     *
     * @var list<string>
     */
    private const EXTENSIONS = ['twig', 'css', 'js', 'mjs', 'html', 'svg'];

    /**
     * Hosts that are identifiers rather than addresses.
     *
     * `xmlns="http://www.w3.org/2000/svg"` is how an SVG element declares what
     * it is. No browser has ever fetched it, and every inline icon has one.
     *
     * @var list<string>
     */
    private const ALLOWED_HOSTS = ['www.w3.org'];

    /** Uploaded files. Not ours, not scannable, and not served as code. */
    private const SKIPPED_DIRECTORIES = ['logos'];

    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * Every external reference, in a stable order.
     *
     * @return list<ExternalAssetReference>
     */
    public function scan(): array
    {
        $found = [];

        foreach (self::DIRECTORIES as $directory) {
            foreach ($this->filesIn($directory) as $relative => $absolute) {
                foreach ($this->referencesIn($relative, $absolute) as $reference) {
                    $found[] = $reference;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> Relative path => absolute path, sorted.
     */
    private function filesIn(string $directory): array
    {
        $base = $this->rootPath . '/' . $directory;

        if (!is_dir($base)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }

            $relative = $directory . '/' . ltrim(
                str_replace('\\', '/', substr($file->getPathname(), strlen($base))),
                '/',
            );

            if ($this->isSkipped($relative)) {
                continue;
            }

            $files[$relative] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    private function isSkipped(string $relative): bool
    {
        foreach (self::SKIPPED_DIRECTORIES as $skipped) {
            if (str_contains($relative, '/' . $skipped . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ExternalAssetReference>
     */
    private function referencesIn(string $relative, string $absolute): array
    {
        $contents = file_get_contents($absolute);

        if ($contents === false) {
            return [];
        }

        $found = [];

        foreach (explode("\n", $contents) as $index => $line) {
            foreach ($this->urlsIn($line) as $url) {
                $found[] = new ExternalAssetReference($relative, $index + 1, $url);
            }
        }

        return $found;
    }

    /**
     * The absolute http(s) URLs on one line, minus the allowed hosts.
     *
     * Deliberately blunt: any absolute URL anywhere in the file, whether it
     * sits in a `src` attribute, a `url()` or a JavaScript string. A check
     * that only understood loading positions would have to keep up with every
     * way a bundler can write one, and this one does not.
     *
     * @return list<string>
     */
    private function urlsIn(string $line): array
    {
        $count = preg_match_all('~https?://[^\s"\'()<>\\\\]+~i', $line, $matches);

        if ($count === false || $count === 0) {
            return [];
        }

        $urls = [];

        foreach ($matches[0] as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if ($host === '' || in_array($host, self::ALLOWED_HOSTS, true)) {
                continue;
            }

            $urls[] = $url;
        }

        return $urls;
    }
}
