<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Controller\Controller;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\HouseholdMemberService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Accepting an invitation to a household.
 *
 * Public, like verifying an address or resetting a password, and for the same
 * reason: the person following the link has never signed in and has no
 * password to sign in with. The token is the whole credential, which is why it
 * is single-use, short-lived and stored only as a hash.
 *
 * Note what the member is *not* asked for: their email address. They are
 * reading the link at it, which is better proof than typing it.
 */
final class InviteController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly HouseholdMemberService $members,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function showForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getQueryParams()['token'] ?? '';
        $token = is_string($token) ? $token : '';

        if ($token === '' || !$this->members->isInviteTokenValid($token)) {
            return $this->render($request, $response->withStatus(410), 'auth/invite_expired.twig');
        }

        return $this->render($request, $response, 'auth/accept_invite.twig', [
            'token' => $token,
            'household' => $this->members->invitedHouseholdName($token),
            'errors' => [],
        ]);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $token = is_scalar($body['token'] ?? null) ? (string) $body['token'] : '';

        try {
            $this->members->acceptInvite(
                $token,
                is_scalar($body['password'] ?? null) ? (string) $body['password'] : '',
                is_scalar($body['password_confirm'] ?? null) ? (string) $body['password_confirm'] : '',
            );
        } catch (ValidationException $exception) {
            // A bad token is not a field error to be shown above a form the
            // user can fix — the link is spent or forged, and re-rendering the
            // form would invite them to keep trying.
            if (array_key_exists('token', $exception->errors())) {
                return $this->render($request, $response->withStatus(410), 'auth/invite_expired.twig');
            }

            return $this->render($request, $response->withStatus(422), 'auth/accept_invite.twig', [
                'token' => $token,
                'household' => $this->members->invitedHouseholdName($token),
                'errors' => $exception->errors(),
            ]);
        }

        // Not signed in here. Setting the password and then using it is one
        // more deliberate step, and it is the step that proves the person at
        // the keyboard now knows the password rather than merely holding a
        // link that somebody may have forwarded to them.
        $this->flash('success', 'flash.invite_accepted');

        return $this->redirect($response, '/login');
    }
}
