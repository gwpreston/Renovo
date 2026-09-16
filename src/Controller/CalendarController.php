<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\CalendarService;
use App\Service\CatchUpService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The month view of what is coming.
 *
 * The same "just mine" toggle as the forecast, and the same catch-up first: a
 * calendar showing a payment date that passed last week would be the most
 * obviously wrong page in the application.
 */
final class CalendarController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly CalendarService $calendar,
        private readonly CatchUpService $catchUp,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $this->catchUp->run($scope);

        $query = $request->getQueryParams();
        $mineOnly = ($query['mine'] ?? '') === '1';
        $month = $this->calendar->resolveMonth(is_string($query['month'] ?? null) ? $query['month'] : null);

        $user = $this->user($request);

        return $this->renderMaybeFragment(
            $request,
            $response,
            'calendar/index.twig',
            'calendar/_grid.twig',
            [
                'calendar' => $this->calendar->month(
                    $scope,
                    $month,
                    $user->weekStartPreference(),
                    $mineOnly ? $scope->userId : null,
                ),
                'mine_only' => $mineOnly,
            ],
        );
    }
}
