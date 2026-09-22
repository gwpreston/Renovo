<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Repository\HouseholdRepository;
use App\Security\SessionInterface;
use App\Service\HouseholdOverviewService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The household, read rather than administered.
 *
 * One GET and nothing else. Who may be added, whose role may change and who
 * may be shown the door are `MemberController`'s questions, behind
 * `ManageHousehold`; this screen answers "who is in this with me, and what is
 * each of us carrying", which is a question an Editor has as much reason to ask
 * as an Owner.
 *
 * It renders what the service hands it. Whether a member's figures are shown at
 * all is an isolation decision and is made in `HouseholdOverviewService`, not
 * here and not in the template — a screen that decided it would be a second
 * place for the rule to live, and the one nobody checks.
 */
final class HouseholdController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly HouseholdOverviewService $overview,
        private readonly HouseholdRepository $households,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        return $this->render($request, $response, 'household/index.twig', [
            'members' => $this->overview->members($scope),
            'household' => $scope->hasHousehold() ? $this->households->findById((int) $scope->householdId) : null,
            'is_isolated' => $scope->restrictsReadsToOwner(),
        ]);
    }
}
