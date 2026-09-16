<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cache-busting for the static files under `public/`.
 *
 * nginx serves `/assets/` with `expires 7d`, which is right for a file whose
 * URL changes when its contents do and badly wrong for one whose does not: an
 * upgrade used to leave every returning browser on the previous stylesheet for
 * a week, so a page would render against CSS that predated its own markup.
 *
 * Appending the file's modification time makes each build a distinct cache
 * key, so the stale entry is bypassed rather than waited out — no hard refresh,
 * and no cache headers to weaken.
 */
final class AssetVersion
{
    /** @var array<string, string> Resolved once per path, per request. */
    private array $versions = [];

    /** @param string $publicPath Absolute path to the web root. */
    public function __construct(private readonly string $publicPath)
    {
    }

    /**
     * The versioned URL for a root-relative asset path.
     *
     * A path that cannot be stat'd — a file removed between deploys, or a
     * typo — is returned untouched. A missing stylesheet is a visible problem
     * on its own and does not need a fatal error on top of it.
     */
    public function url(string $path): string
    {
        return $this->versions[$path] ??= $this->build($path);
    }

    private function build(string $path): string
    {
        // Split off anything that is not part of the filename: a query string
        // must not reach the stat, and a fragment is never sent at all.
        $mark = strcspn($path, '?#');
        $file = substr($path, 0, $mark);
        $rest = substr($path, $mark);

        $modified = $this->modifiedAt($file);
        if ($modified === null) {
            return $path;
        }

        $version = '?v=' . dechex($modified);
        if (str_starts_with($rest, '?')) {
            return $file . $version . '&' . substr($rest, 1);
        }

        return $file . $version . $rest;
    }

    /** The file's modification time, or null if there is no such file. */
    private function modifiedAt(string $file): ?int
    {
        $absolute = $this->publicPath . '/' . ltrim($file, '/');
        if (!is_file($absolute)) {
            return null;
        }

        $modified = filemtime($absolute);

        return $modified === false ? null : $modified;
    }
}
