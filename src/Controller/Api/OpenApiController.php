<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Api\OpenApiDocument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the API's own description.
 *
 * Unauthenticated, and deliberately so: a client needs the document in order to
 * learn how to authenticate, and the document contains no instance data — no
 * names, no figures, not even the instance's title. It describes the shape of
 * an endpoint, which anybody who can reach the login page can already infer.
 *
 * Both representations come from the same file. The YAML is served byte for
 * byte, and the JSON is that file parsed and re-encoded, so the two cannot
 * describe different APIs.
 */
final class OpenApiController extends ApiController
{
    public function __construct(private readonly OpenApiDocument $document)
    {
    }

    public function asYaml(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->document->yaml());

        return $response
            ->withHeader('Content-Type', 'application/yaml; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function asJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->document->toArray());
    }
}
