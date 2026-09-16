<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\I18n\Translator;
use App\Controller\Controller;
use App\Security\SessionInterface;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class VerifyEmailController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly AuthService $auth,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getQueryParams()['token'] ?? '';
        $verified = is_string($token) && $token !== '' && $this->auth->verifyEmail($token);

        if ($verified) {
            $this->flash('success', 'flash.email_confirmed');

            return $this->redirect($response, '/login');
        }

        return $this->render($request, $response->withStatus(410), 'auth/verify_failed.twig');
    }
}
