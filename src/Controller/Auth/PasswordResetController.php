<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\I18n\Translator;
use App\Controller\Controller;
use App\Security\SessionInterface;
use App\Service\PasswordResetService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class PasswordResetController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly PasswordResetService $resets,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function showRequestForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'auth/forgot_password.twig', [
            'values' => [],
            'errors' => [],
            'minutes' => $this->resets->tokenLifetimeMinutes(),
        ]);
    }

    public function submitRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $email = is_scalar($body['email'] ?? null) ? (string) $body['email'] : '';

        try {
            $this->resets->request($email, $this->clientIp($request));
        } catch (ValidationException $exception) {
            return $this->render($request, $response->withStatus(429), 'auth/forgot_password.twig', [
                'values' => ['email' => $email],
                'errors' => $exception->errors(),
                'minutes' => $this->resets->tokenLifetimeMinutes(),
            ]);
        }

        // The same page is shown whether or not the address exists. It
        // repeats the address as typed — the reader's own input, which tells
        // them nothing new — so "Send again" can post it back.
        return $this->render($request, $response, 'auth/forgot_password_sent.twig', [
            'email' => trim($email),
            'minutes' => $this->resets->tokenLifetimeMinutes(),
        ]);
    }

    public function showResetForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getQueryParams()['token'] ?? '';
        $token = is_string($token) ? $token : '';

        if ($token === '' || !$this->resets->isTokenValid($token)) {
            return $this->renderExpired($request, $response);
        }

        return $this->render($request, $response, 'auth/reset_password.twig', [
            'token' => $token,
            'errors' => [],
        ]);
    }

    public function submitReset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $token = is_scalar($body['token'] ?? null) ? (string) $body['token'] : '';

        try {
            $this->resets->reset(
                $token,
                is_scalar($body['password'] ?? null) ? (string) $body['password'] : '',
                is_scalar($body['password_confirm'] ?? null) ? (string) $body['password_confirm'] : '',
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['token'])) {
                return $this->renderExpired($request, $response);
            }

            return $this->render($request, $response->withStatus(422), 'auth/reset_password.twig', [
                'token' => $token,
                'errors' => $errors,
            ]);
        }

        // A page of its own rather than a flash over the sign-in form: the
        // reset is finished, and the one thing left to do is sign in with the
        // new password.
        return $this->render($request, $response, 'auth/reset_done.twig');
    }

    private function renderExpired(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response->withStatus(410), 'auth/reset_expired.twig', [
            'minutes' => $this->resets->tokenLifetimeMinutes(),
        ]);
    }
}
