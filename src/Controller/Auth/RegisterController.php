<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\I18n\Translator;
use App\Controller\Controller;
use App\Security\SessionInterface;
use App\Service\AuthService;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

final class RegisterController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly AuthService $auth,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function showForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertRegistrationOpen($request);

        return $this->render($request, $response, 'auth/register.twig', [
            'values' => [],
            'errors' => [],
        ]);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertRegistrationOpen($request);

        $body = $this->body($request);
        $values = [
            'email' => is_scalar($body['email'] ?? null) ? (string) $body['email'] : '',
            'display_name' => is_scalar($body['display_name'] ?? null) ? (string) $body['display_name'] : '',
        ];

        try {
            $this->auth->register(
                $values['email'],
                $values['display_name'],
                is_scalar($body['password'] ?? null) ? (string) $body['password'] : '',
                is_scalar($body['password_confirm'] ?? null) ? (string) $body['password_confirm'] : '',
            );
        } catch (ValidationException $exception) {
            return $this->render($request, $response->withStatus(422), 'auth/register.twig', [
                'values' => $values,
                'errors' => $exception->errors(),
            ]);
        }

        return $this->render($request, $response, 'auth/register_sent.twig', [
            'email' => $values['email'],
        ]);
    }

    private function assertRegistrationOpen(ServerRequestInterface $request): void
    {
        if (!$this->settings->registrationAllowed()) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.auth.registration_closed'));
        }
    }
}
