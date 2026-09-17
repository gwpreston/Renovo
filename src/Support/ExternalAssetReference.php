<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One third-party URL found where the browser could load from it.
 */
final class ExternalAssetReference
{
    /**
     * @param string $file Path relative to the repository root.
     * @param int    $line 1-based line number.
     * @param string $url  The offending URL, as written.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $url,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s:%d  %s', $this->file, $this->line, $this->url);
    }
}
