<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\SavedViewService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Saving and forgetting a set of list filters.
 *
 * Both routes act on the signed-in user's own rows and name no permission, for
 * the same reason the notification and security pages do not: the id comes
 * from the session and cannot be pointed at anybody else.
 */
final class SavedViewController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly SavedViewService $views,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $name = is_scalar($body['name'] ?? null) ? (string) $body['name'] : '';

        // The filters come from the hidden field the list posts, not from this
        // request's own query string: the form lives on a page that may have
        // been reached by htmx, whose URL is not necessarily the one the user
        // is looking at.
        parse_str(is_scalar($body['query'] ?? null) ? (string) $body['query'] : '', $query);

        try {
            $this->views->save($this->scope($request), $name, $query);
            $this->flash('success', 'flash.saved_view_created');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, $this->listPath($query));
    }

    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $deleted = $this->views->delete($this->scope($request), (int) $id);

        $this->flash(
            $deleted ? 'success' : 'error',
            $deleted ? 'flash.saved_view_deleted' : 'flash.saved_view_missing',
        );

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listPath(array $query): string
    {
        $rebuilt = http_build_query($query);

        return '/subscriptions' . ($rebuilt === '' ? '' : '?' . $rebuilt);
    }
}
