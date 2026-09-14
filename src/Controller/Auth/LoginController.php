<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Controller\Controller;
use App\Repository\MembershipRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\AuthService;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class LoginController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        private readonly AuthService $auth,
        private readonly MembershipRepository $memberships,
        private readonly InstanceSettingsService $settings,
        private readonly CsrfTokenManager $csrf,
    ) {
        parent::__construct($view, $session);
    }

    public function showForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (is_int($this->session->get(AuthenticationMiddleware::SESSION_USER_ID))) {
            return $this->redirect($response, '/');
        }

        return $this->render($request, $response, 'auth/login.twig', [
            'next' => $this->nextTarget($request),
            'registration_allowed' => $this->settings->registrationAllowed(),
            'values' => [],
            'errors' => [],
        ]);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $email = is_scalar($body['email'] ?? null) ? (string) $body['email'] : '';
        $password = is_scalar($body['password'] ?? null) ? (string) $body['password'] : '';

        try {
            $user = $this->auth->attemptLogin($email, $password, $this->clientIp($request));
        } catch (ValidationException $exception) {
            return $this->render($request, $response->withStatus(422), 'auth/login.twig', [
                'next' => $this->nextTarget($request),
                'registration_allowed' => $this->settings->registrationAllowed(),
                'values' => ['email' => $email],
                'errors' => $exception->errors(),
            ]);
        }

        // A new privilege level gets a new session id and a new CSRF token, so
        // a token captured before login cannot be replayed after it.
        $this->session->regenerate();
        $this->csrf->rotate();

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $user->id);

        $memberships = $this->memberships->findAllForUser($user->id);
        if ($memberships !== []) {
            $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $memberships[0]->householdId);
        }

        $this->flash('success', sprintf('Welcome back, %s.', $user->displayName));

        return $this->redirect($response, $this->safeRedirectTarget($this->nextTarget($request)));
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->session->clear();
        $this->session->regenerate();
        $this->flash('success', 'You have been signed out.');

        return $this->redirect($response, '/login');
    }

    private function nextTarget(ServerRequestInterface $request): string
    {
        $params = $request->getQueryParams();
        $body = $this->body($request);

        foreach ([$body['next'] ?? null, $params['next'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '/';
    }

    /**
     * Only same-site, path-only redirects are followed: an attacker must not
     * be able to turn the login form into an open redirect by supplying
     * ?next=https://elsewhere.example.
     */
    private function safeRedirectTarget(string $candidate): string
    {
        if ($candidate === '' || !str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return '/';
        }

        return $candidate;
    }
}
