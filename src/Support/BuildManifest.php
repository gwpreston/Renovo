<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;
use RuntimeException;

/**
 * Resolves a logical asset name to the built file that currently holds it.
 *
 * The asset build writes content-hashed files into `public/build/` and a
 * manifest mapping each source path to its hashed output. A template asks for
 * `app.css` and gets `/build/app-4f2a1c.css`, so a returning browser after an
 * upgrade fetches the new stylesheet instead of serving the previous one from
 * a week-long cache — the same problem AssetVersion solves for the files that
 * do not go through the build, solved here by the filename itself.
 *
 * The manifest's own keys are source-relative (`assets/css/app.css`), which is
 * an implementation detail of the bundler and no business of a template's, so
 * lookups are by filename.
 */
final class BuildManifest
{
    /** @var array<string, string>|null Logical name => hashed file, read once. */
    private ?array $files = null;

    /**
     * @param string $manifestPath Absolute path to the build's manifest.json.
     * @param string $baseUrl      The URL prefix the output is served under.
     *                             Must match Vite's `base` and the nginx
     *                             location block.
     */
    public function __construct(
        private readonly string $manifestPath,
        private readonly string $baseUrl = '/build/',
    ) {
    }

    /**
     * The URL of the built file for a logical name, e.g. `app.css`.
     *
     * A missing manifest or a name that is not in it throws. That is a louder
     * failure than returning the name unchanged, and deliberately so: a
     * stylesheet that 404s produces a page that looks broken for a reason
     * nobody can see, whereas the message below names the command that fixes
     * it. The production image is built with its assets already in place, so
     * the only way to reach this is to have skipped the build.
     */
    public function url(string $name): string
    {
        $files = $this->files ??= $this->read();

        if (!isset($files[$name])) {
            throw new RuntimeException(sprintf(
                'No built asset named "%s". Run "npm install && npm run build" '
                . '(or "docker compose run --rm assets npm run build"). Known: %s.',
                $name,
                $files === [] ? 'none' : implode(', ', array_keys($files)),
            ));
        }

        return $this->baseUrl . $files[$name];
    }

    /** Whether the build has run, for a caller that would rather ask than catch. */
    public function isBuilt(): bool
    {
        return is_file($this->manifestPath);
    }

    /**
     * @return array<string, string>
     */
    private function read(): array
    {
        if (!is_file($this->manifestPath)) {
            throw new RuntimeException(sprintf(
                'The asset manifest is missing (%s). Run "npm install && npm run build" '
                . '(or "docker compose run --rm assets npm run build") to produce it.',
                $this->manifestPath,
            ));
        }

        $json = file_get_contents($this->manifestPath);
        if ($json === false) {
            throw new RuntimeException(sprintf('Could not read the asset manifest (%s).', $this->manifestPath));
        }

        try {
            /** @var array<string, array<string, mixed>> $entries */
            $entries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('The asset manifest (%s) is not valid JSON.', $this->manifestPath),
                0,
                $e,
            );
        }

        $files = [];

        foreach ($entries as $key => $entry) {
            if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
                continue;
            }

            // Only the entry points get a logical name. The font files and the
            // lazily-loaded chart chunk are in the manifest too, and they are
            // referenced by the built CSS and JS themselves — a template has
            // no reason to name one, and letting it would mean a template
            // could ask for a file whose existence is a bundler decision.
            if (($entry['isEntry'] ?? false) !== true) {
                continue;
            }

            $files[basename($key)] = $entry['file'];
        }

        return $files;
    }
}
