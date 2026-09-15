<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Controller\Controller;
use App\Domain\Entity\User;
use App\Security\SessionInterface;
use App\Service\Auth\SignInService;
use App\Service\Auth\TwoFactorService;
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
        private readonly InstanceSettingsService $settings,
        private readonly SignInService $signIn,
        private readonly TwoFactorService $twoFactor,
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

        $next = $this->safeRedirectTarget($this->nextTarget($request));

        // The password is only half the answer when a second factor is set up.
        // Note what does NOT happen here: the session's user id is not written,
        // so as far as every authenticated route is concerned this browser is
        // still anonymous until the factor is presented.
        if ($this->twoFactor->isRequiredFor($user->id)) {
            // Still a privilege change — a fixed session id must not survive
            // into the challenge, where it could be waiting for the sign-in it
            // is about to be granted.
            $this->session->regenerate();
            $this->twoFactor->beginChallenge($user, $next);

            return $this->redirect($response, '/login/two-factor');
        }

        $this->signIn->establish($user, SignInService::METHOD_PASSWORD);

        $this->flash('success', sprintf('Welcome back, %s.', $user->displayName));

        return $this->redirect($response, $next);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_USER);

        if ($user instanceof User) {
            $this->signIn->signOut($user);
        } else {
            $this->session->clear();
            $this->session->regenerate();
        }

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
