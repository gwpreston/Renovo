<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\TokenAbility;
use App\Service\ApiTokenService;
use App\Service\ValidationException;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use App\Security\SessionInterface;

/**
 * Issuing and revoking the signed-in user's API tokens.
 *
 * No permission is named on these routes, for the same reason the notification
 * channels and the passkey routes name none: every one of them acts on the
 * authenticated user's own id, and a token can never do more than its owner
 * can. A Viewer may issue one and will find it reads exactly what they can read
 * and writes nothing.
 *
 * The full token is put in a one-shot flash rather than held in the page model,
 * so a refresh of the settings page does not redisplay it and it never reaches
 * a template through a route that could be linked to.
 */
final class ApiTokenController extends Controller
{
    private const FLASH_NEW_TOKEN = 'new_api_token';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        private readonly ApiTokenService $tokens,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $issued = $this->session->get(self::FLASH_NEW_TOKEN);
        $this->session->remove(self::FLASH_NEW_TOKEN);

        return $this->render($request, $response, 'tokens/index.twig', [
            'tokens' => $this->tokens->listFor($this->user($request)),
            'issued_token' => is_string($issued) ? $issued : null,
            'abilities' => TokenAbility::cases(),
            'feed_url' => rtrim((string) $request->getUri()->withPath('')->withQuery(''), '/')
                . '/api/v1/calendar.ics',
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $scope = $this->scope($request);

        try {
            $token = $this->tokens->issue(
                $this->user($request),
                $scope->householdId,
                is_scalar($body['name'] ?? null) ? (string) $body['name'] : '',
                TokenAbility::fromString(is_scalar($body['abilities'] ?? null) ? (string) $body['abilities'] : ''),
                $this->expiry($body),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $message) {
                $this->flash('error', $message);
            }

            return $this->redirectAfterWrite($request, $response, '/settings/api-tokens');
        }

        $this->session->set(self::FLASH_NEW_TOKEN, $token);
        $this->flash('success', 'Token created. Copy it now — it is not shown again.');

        return $this->redirectAfterWrite($request, $response, '/settings/api-tokens');
    }

    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $revoked = $this->tokens->revoke($this->user($request), (int) $id);

        $this->flash(
            $revoked ? 'success' : 'error',
            $revoked ? 'Token revoked.' : 'That token could not be revoked.',
        );

        return $this->redirectAfterWrite($request, $response, '/settings/api-tokens');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function expiry(array $body): ?DateTimeImmutable
    {
        $raw = is_scalar($body['expires_at'] ?? null) ? trim((string) $body['expires_at']) : '';
        if ($raw === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        // End of the chosen day: a token that said it expired on the 31st and
        // stopped working at midnight on the 30th would be a support ticket.
        return $date === false ? null : $date->setTime(23, 59, 59);
    }
}
