<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\I18n\Translator;
use App\Controller\Controller;
use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Repository\AuthAttemptRepository;
use App\Security\SessionInterface;
use App\Service\Auth\SignInService;
use App\Service\Auth\TotpService;
use App\Service\Auth\TwoFactorService;
use App\Service\Auth\WebAuthnService;
use App\Service\AuditLogService;
use App\Service\RateLimiter;
use App\Service\ValidationError;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The second-factor step of signing in.
 *
 * Deliberately outside the authenticated route group. Everything here happens
 * to a browser that is not signed in and must not be treated as if it were: the
 * only thing it holds is a short-lived pending challenge, and the identity it
 * names comes from that challenge, never from the request.
 *
 * Failures are throttled on their own budget, because a six-digit code is
 * guessable at a rate a password is not.
 */
final class TwoFactorController extends Controller
{
    private const CHALLENGE_SESSION_KEY = 'two_factor_assertion_options';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly TwoFactorService $twoFactor,
        private readonly TotpService $totp,
        private readonly WebAuthnService $webAuthn,
        private readonly SignInService $signIn,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLogService $audit,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function showChallenge(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->twoFactor->pendingUser();
        if ($user === null) {
            return $this->redirect($response, '/login');
        }

        return $this->renderChallenge($request, $response, $user);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->twoFactor->pendingUser();
        if ($user === null) {
            $this->flash('error', 'flash.two_factor_expired');

            return $this->redirect($response, '/login');
        }

        $body = $this->body($request);
        $code = is_scalar($body['code'] ?? null) ? (string) $body['code'] : '';
        $useRecovery = ($body['mode'] ?? '') === 'recovery';

        $remaining = $this->rateLimiter->remainingLockoutSeconds(
            AuthAttemptRepository::KIND_TWO_FACTOR,
            $user->email,
            $this->clientIp($request),
        );

        if ($remaining > 0) {
            return $this->renderChallenge($request, $response->withStatus(429), $user, [
                'code' => new ValidationError(
                    'error.auth.throttled',
                    ['minutes' => max(1, (int) ceil($remaining / 60))],
                ),
            ], $useRecovery);
        }

        $accepted = $useRecovery
            ? $this->twoFactor->redeemRecoveryCode($user, $code)
            : $this->totp->verify($user->id, $code);

        if (!$accepted) {
            $this->rateLimiter->recordFailure(
                AuthAttemptRepository::KIND_TWO_FACTOR,
                $user->email,
                $this->clientIp($request),
            );

            $this->audit->recordAnonymous(AuditAction::TwoFactorFailed, $user->email, $user, [
                'method' => $useRecovery ? 'recovery_code' : 'totp',
            ]);

            return $this->renderChallenge($request, $response->withStatus(422), $user, [
                'code' => $useRecovery
                    ? new ValidationError('error.recovery_code.invalid')
                    : new ValidationError('error.totp.code_wrong'),
            ], $useRecovery);
        }

        return $this->complete(
            $response,
            $user,
            $useRecovery ? SignInService::METHOD_PASSWORD_RECOVERY : SignInService::METHOD_PASSWORD_TOTP,
            $this->clientIp($request),
        );
    }

    /**
     * Options for presenting a passkey as the second factor.
     */
    public function passkeyOptions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->twoFactor->pendingUser();
        if ($user === null) {
            return $this->json(
                $response->withStatus(401),
                ['error' => $this->translator->trans('error.two_factor.expired')],
            );
        }

        $options = $this->webAuthn->serializeOptions($this->webAuthn->authenticationOptions($user));
        $this->session->set(self::CHALLENGE_SESSION_KEY, $options);

        return $this->jsonRaw($response, $options);
    }

    public function passkeyVerify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->twoFactor->pendingUser();
        if ($user === null) {
            return $this->json(
                $response->withStatus(401),
                ['error' => $this->translator->trans('error.two_factor.expired')],
            );
        }

        $stored = $this->session->get(self::CHALLENGE_SESSION_KEY);
        if (!is_string($stored) || $stored === '') {
            return $this->json(
                $response->withStatus(400),
                ['error' => $this->translator->trans('error.two_factor.restart_passkey')],
            );
        }

        try {
            $this->webAuthn->completeAuthentication(
                (string) $request->getBody(),
                $this->webAuthn->deserializeRequestOptions($stored),
                $user->id,
            );
        } catch (ValidationException $exception) {
            $this->rateLimiter->recordFailure(
                AuthAttemptRepository::KIND_TWO_FACTOR,
                $user->email,
                $this->clientIp($request),
            );

            $this->audit->recordAnonymous(AuditAction::TwoFactorFailed, $user->email, $user, [
                'method' => 'passkey',
            ]);

            return $this->json($response->withStatus(422), ['error' => $this->errorSentence($exception)]);
        } finally {
            // One challenge, one use, however it ended.
            $this->session->remove(self::CHALLENGE_SESSION_KEY);
        }

        $target = $this->signIn->landingFor($user, $this->twoFactor->pendingTarget());
        $this->completeSignIn($user, SignInService::METHOD_PASSWORD_PASSKEY, $this->clientIp($request));

        return $this->json($response, ['redirect' => $target]);
    }

    /**
     * Give up and go back to the login form.
     */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->twoFactor->clear();
        $this->session->remove(self::CHALLENGE_SESSION_KEY);

        return $this->redirect($response, '/login');
    }

    private function complete(
        ResponseInterface $response,
        User $user,
        string $method,
        string $ipAddress,
    ): ResponseInterface {
        // Read before the challenge is cleared, and resolved through the same
        // rule the one-factor path uses.
        $target = $this->signIn->landingFor($user, $this->twoFactor->pendingTarget());
        $this->completeSignIn($user, $method, $ipAddress);

        $this->flash('success', 'flash.welcome_back', ['name' => $user->displayName]);

        return $this->redirect($response, $target);
    }

    private function completeSignIn(User $user, string $method, string $ipAddress): void
    {
        $this->rateLimiter->recordSuccess(AuthAttemptRepository::KIND_TWO_FACTOR, $user->email, $ipAddress);

        $this->audit->record(AuditAction::TwoFactorSucceeded, $user, ['method' => $method]);

        $remember = $this->twoFactor->pendingRemember();

        // Cleared before the session is established, not after: a pending
        // challenge left behind is a second door into the account it names.
        $this->twoFactor->clear();
        $this->session->remove(self::CHALLENGE_SESSION_KEY);

        $this->signIn->establish($user, $method, $remember);
    }

    /**
     * @param array<string, ValidationError> $errors
     */
    private function renderChallenge(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        array $errors = [],
        bool $recovery = false,
    ): ResponseInterface {
        return $this->render($request, $response, 'auth/two_factor.twig', [
            'methods' => $this->twoFactor->availableMethods($user->id),
            // The address, not the name: it is what the authenticator app
            // lists the account under, so it is what tells the reader which
            // of their codes to type.
            'email' => $user->email,
            'display_name' => $user->displayName,
            'errors' => $errors,
            // Keep the recovery form open when it was the one that failed.
            'recovery_open' => $recovery,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        return $this->jsonRaw($response, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function jsonRaw(ResponseInterface $response, string $json): ResponseInterface
    {
        $response->getBody()->write($json);

        return $response->withHeader('Content-Type', 'application/json');
    }
}
