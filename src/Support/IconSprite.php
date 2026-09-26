<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The icon sprite the asset build produced, and the names in it.
 *
 * The build reads `assets/theme/icons.json`, takes only those drawings out of
 * Lucide, and writes two files into `public/build/`: the sprite under a
 * content-hashed name, and `icons.json` beside it recording that name and the
 * list of icons inside. This reads the second, so a template can ask for
 * `icon('wallet')` and get markup that points at the right file — and so a
 * name that is not in the sprite is caught here, on the server, rather than
 * drawing an empty square that nobody notices is empty.
 *
 * An unknown name throws when `$strict` (development and tests), and is
 * logged and rendered as nothing otherwise: a missing picture beside a label
 * is a bug worth a log line in production, not a 500 on every page that
 * carries the label.
 */
final class IconSprite
{
    /** @var array{sprite: string, names: list<string>}|null */
    private ?array $index = null;

    public function __construct(
        private readonly string $indexPath,
        private readonly bool $strict,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $baseUrl = '/build/',
    ) {
    }

    /** The sprite's URL, for a script that builds an icon of its own. */
    public function url(): string
    {
        return $this->baseUrl . $this->index()['sprite'];
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->index()['names'], true);
    }

    /**
     * An icon as markup.
     *
     * Decorative by construction — `aria-hidden` and not focusable — because
     * an icon beside a label says nothing the label does not. An icon-only
     * control carries its own catalogued `aria-label` on the control.
     *
     * The class is what sizes it; `icon` is the generic one-em square.
     */
    public function render(string $name, string $class = 'icon'): string
    {
        if (!$this->has($name)) {
            if ($this->strict) {
                throw new RuntimeException(sprintf(
                    'No icon named "%s" in the sprite. Add it to assets/theme/icons.json and rebuild.',
                    $name,
                ));
            }

            $this->logger?->warning('Unknown icon requested.', ['icon' => $name]);

            return '';
        }

        return sprintf(
            '<svg class="%s" aria-hidden="true" focusable="false"><use href="%s#%s"></use></svg>',
            htmlspecialchars($class, ENT_QUOTES),
            htmlspecialchars($this->url(), ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
        );
    }

    /**
     * @return array{sprite: string, names: list<string>}
     */
    private function index(): array
    {
        return $this->index ??= $this->read();
    }

    /**
     * @return array{sprite: string, names: list<string>}
     */
    private function read(): array
    {
        $json = is_file($this->indexPath) ? file_get_contents($this->indexPath) : false;

        if ($json === false) {
            throw new RuntimeException(sprintf(
                'The icon index is missing (%s). Run "npm install && npm run build" '
                . '(or "docker compose run --rm assets npm run build") to produce it.',
                $this->indexPath,
            ));
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('The icon index (%s) is not valid JSON.', $this->indexPath), 0, $e);
        }

        if (
            !is_array($data)
            || !is_string($data['sprite'] ?? null)
            || !is_array($data['names'] ?? null)
        ) {
            throw new RuntimeException(sprintf('The icon index (%s) is not in the expected shape.', $this->indexPath));
        }

        return [
            'sprite' => $data['sprite'],
            'names' => array_values(array_filter($data['names'], 'is_string')),
        ];
    }
}
