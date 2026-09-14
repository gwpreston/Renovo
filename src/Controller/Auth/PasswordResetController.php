<?php

declare(strict_types=1);

namespace App\Controller\Auth;

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
        private readonly PasswordResetService $resets,
    ) {
        parent::__construct($view, $session);
    }

    public function showRequestForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'auth/forgot_password.twig', [
            'values' => [],
            'errors' => [],
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
            ]);
        }

        // The same page is shown whether or not the address exists.
        return $this->render($request, $response, 'auth/forgot_password_sent.twig');
    }

    public function showResetForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getQueryParams()['token'] ?? '';
        $token = is_string($token) ? $token : '';

        if ($token === '' || !$this->resets->isTokenValid($token)) {
            return $this->render($request, $response->withStatus(410), 'auth/reset_expired.twig');
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
                return $this->render($request, $response->withStatus(410), 'auth/reset_expired.twig');
            }

            return $this->render($request, $response->withStatus(422), 'auth/reset_password.twig', [
                'token' => $token,
                'errors' => $errors,
            ]);
        }

        $this->flash('success', 'Your password has been changed. Sign in with it now.');

        return $this->redirect($response, '/login');
    }
}
