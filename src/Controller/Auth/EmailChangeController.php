<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Controller\Controller;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\AccountService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The last step of changing an address.
 *
 * Public rather than behind authentication, deliberately. The link arrives at
 * the *new* address, which may well be read on a different device from the one
 * the member is signed in on — a phone, in practice — and requiring a session
 * there would mean signing in with the old address to confirm the new one.
 *
 * Nothing is disclosed by that. The token is single-use, expires in an hour,
 * and the account it names is only discoverable by holding it.
 */
final class EmailChangeController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly AccountService $account,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function confirm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getQueryParams()['token'] ?? '';

        try {
            $this->account->confirmEmailChange(is_string($token) ? $token : '');
        } catch (ValidationException $exception) {
            return $this->render($request, $response->withStatus(410), 'auth/email_change_failed.twig', [
                'errors' => $exception->errors(),
            ]);
        }

        $this->flash('success', 'flash.email_change_confirmed');

        return $this->redirect($response, '/profile');
    }
}
