<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\SessionInterface;
use App\Service\CancellationService;
use App\Service\CatchUpService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Cancel-by deadlines, most urgent first.
 */
final class CancellationController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        private readonly CancellationService $cancellations,
        private readonly CatchUpService $catchUp,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        // Deadlines are derived from payment dates, so those have to be current
        // before the list means anything.
        $this->catchUp->run($scope);

        return $this->render($request, $response, 'cancellations/index.twig', [
            'deadlines' => $this->cancellations->deadlines($scope),
            'urgent_days' => CancellationService::URGENT_DAYS,
        ]);
    }
}
