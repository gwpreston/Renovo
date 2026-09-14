<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Currency;
use App\Domain\IsolationMode;
use App\Repository\MembershipRepository;
use App\Security\SessionInterface;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\SetupService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * First-run wizard. Reachable only while the instance has no users; the
 * SetupGuardMiddleware closes it afterwards.
 */
final class SetupController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        private readonly SetupService $setup,
        private readonly MembershipRepository $memberships,
        private readonly ExchangeRateProviderRegistry $rateProviders,
    ) {
        parent::__construct($view, $session);
    }

    public function showForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'setup/wizard.twig', $this->formData([
            'base_currency' => 'GBP',
            'isolation_mode' => IsolationMode::Shared->value,
        ]));
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $user = $this->setup->complete($body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'setup/wizard.twig',
                $this->formData($body, $exception->errors()),
            );
        }

        $this->session->regenerate();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $user->id);

        $memberships = $this->memberships->findAllForUser($user->id);
        if ($memberships !== []) {
            $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $memberships[0]->householdId);
        }

        $this->flash('success', 'Your instance is ready. Add your first subscription to get started.');

        return $this->redirect($response, '/');
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function formData(array $values, array $errors = []): array
    {
        return [
            'currencies' => Currency::common(),
            'isolation_modes' => IsolationMode::cases(),
            'rate_providers' => $this->rateProviders->all(),
            'default_rate_provider' => $this->rateProviders->default()->key(),
            'values' => $values,
            'errors' => $errors,
        ];
    }
}
