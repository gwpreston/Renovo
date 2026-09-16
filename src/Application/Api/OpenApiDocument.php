<?php

declare(strict_types=1);

namespace App\Application\Api;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the checked-in OpenAPI document.
 *
 * The YAML file is the source of truth, not a rendering of one. Generating the
 * spec from annotations on the controllers would make it a mirror: it would
 * describe whatever the code does, including the parts that are wrong, and
 * "the API matches its spec" would be a tautology rather than a test.
 *
 * Written by hand and served verbatim, it is a contract the code is measured
 * against instead — which is what the route-coverage test in CI actually
 * measures. Add an endpoint without describing it and the build fails; describe
 * one that does not exist and the build fails too.
 */
final class OpenApiDocument
{
    public function __construct(private readonly string $path)
    {
    }

    public function yaml(): string
    {
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('The OpenAPI document "%s" is missing.', $this->path));
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $parsed = Yaml::parse($this->yaml());

        if (!is_array($parsed)) {
            throw new RuntimeException('The OpenAPI document is not a YAML mapping.');
        }

        return $parsed;
    }

    /**
     * Every path and method the document describes.
     *
     * Used by the coverage test, which compares this against Slim's own route
     * table in both directions.
     *
     * @return list<string> e.g. "GET /api/v1/subscriptions"
     */
    public function operations(): array
    {
        $document = $this->toArray();
        $paths = $document['paths'] ?? [];

        if (!is_array($paths)) {
            return [];
        }

        $operations = [];
        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }

            foreach (array_keys($methods) as $method) {
                if (!is_string($method) || !in_array(strtolower($method), self::METHODS, true)) {
                    continue;
                }

                $operations[] = strtoupper($method) . ' ' . $path;
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * The HTTP methods an OpenAPI path item may carry. Anything else under a
     * path — `parameters`, `summary`, a `$ref` — is not an operation.
     */
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
}
