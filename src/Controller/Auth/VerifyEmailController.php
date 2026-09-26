<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\I18n\Translator;
use App\Controller\Controller;
use App\Security\SessionInterface;
use App\Repository\TokenRepository;
use App\Service\AuthService;
use App\Service\ValidationException;
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
        $token = is_string($token) ? $token : '';

        if ($token !== '' && $this->auth->verifyEmail($token)) {
            return $this->render($request, $response, 'auth/verify_done.twig');
        }

        // A link that has been used is not a failure the reader needs to fix:
        // the address is confirmed, and the thing to do is sign in. Only a
        // link that ran out, or never was one, needs a new one sent.
        $state = $token === '' ? TokenRepository::STATE_UNKNOWN : $this->auth->verificationLinkState($token);

        return $this->render($request, $response->withStatus(410), 'auth/verify_failed.twig', [
            'already_used' => $state === TokenRepository::STATE_USED,
            'days' => $this->auth->verificationLifetimeDays(),
        ]);
    }

    /**
     * Send the confirmation link again.
     *
     * The page that follows is the one registration ends on, and it is the
     * same whatever became of the request — sent, already confirmed, or no
     * such account — so this cannot be used to learn who has signed up.
     */
    public function resend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $email = trim(is_scalar($body['email'] ?? null) ? (string) $body['email'] : '');

        try {
            $this->auth->resendVerification($email, $this->clientIp($request));
        } catch (ValidationException $exception) {
            return $this->render($request, $response->withStatus(429), 'auth/register_sent.twig', [
                'email' => $email,
                'days' => $this->auth->verificationLifetimeDays(),
                'errors' => $exception->errors(),
            ]);
        }

        return $this->render($request, $response, 'auth/register_sent.twig', [
            'email' => $email,
            'days' => $this->auth->verificationLifetimeDays(),
            'resent' => true,
        ]);
    }
}
