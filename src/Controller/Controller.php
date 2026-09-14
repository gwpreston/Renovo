<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Entity\User;
use App\Security\Scope;
use App\Security\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Shared plumbing for controllers: rendering, redirecting, flash messages and
 * reading the request attributes the middleware stack put there.
 *
 * Controllers stay thin — they translate HTTP into a service call and back
 * again. Anything that decides something belongs in a service.
 */
abstract class Controller
{
    public function __construct(
        protected readonly Twig $view,
        protected readonly SessionInterface $session,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $data = [],
    ): ResponseInterface {
        return $this->view->render($response, $template, $this->decorate($request, $data));
    }

    /**
     * Add the values every template expects: who is signed in, what they may
     * do, and any flash messages waiting to be shown.
     *
     * Doing it here rather than in each controller means a new page cannot
     * forget the navigation bar's context.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function decorate(ServerRequestInterface $request, array $data): array
    {
        return $data + [
            'current_user' => $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_USER),
            'scope' => $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_SCOPE),
            'flashes' => $this->session->isStarted() ? $this->session->consumeFlashes() : [],
            'current_path' => $request->getUri()->getPath(),
        ];
    }

    /**
     * Render a full page, or just its fragment when htmx asked for one.
     *
     * @param array<string, mixed> $data
     */
    protected function renderMaybeFragment(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $fullTemplate,
        string $fragmentTemplate,
        array $data = [],
    ): ResponseInterface {
        $template = $this->isHtmx($request) ? $fragmentTemplate : $fullTemplate;

        return $this->view->render($response, $template, $this->decorate($request, $data));
    }

    protected function isHtmx(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('HX-Request') === 'true';
    }

    protected function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /**
     * Redirect in a way htmx also honours, so a POST made from a fragment
     * navigates the browser instead of swapping a whole page into a corner.
     */
    protected function redirectAfterWrite(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $location,
    ): ResponseInterface {
        if ($this->isHtmx($request)) {
            return $response->withHeader('HX-Redirect', $location)->withStatus(204);
        }

        return $this->redirect($response, $location);
    }

    protected function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_USER);
        if (!$user instanceof User) {
            throw new \RuntimeException('This route requires authentication middleware.');
        }

        return $user;
    }

    protected function scope(ServerRequestInterface $request): Scope
    {
        $scope = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_SCOPE);
        if (!$scope instanceof Scope) {
            throw new \RuntimeException('This route requires authentication middleware.');
        }

        return $scope;
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function flash(string $type, string $message): void
    {
        $this->session->flash($type, $message);
    }

    protected function notFound(ServerRequestInterface $request): HttpNotFoundException
    {
        return new HttpNotFoundException($request);
    }

    protected function clientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}
