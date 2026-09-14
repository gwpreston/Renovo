<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\BudgetPeriod;
use App\Domain\Currency;
use App\Repository\MembershipRepository;
use App\Security\SessionInterface;
use App\Service\BudgetService;
use App\Service\CategoryService;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Budgets. Thin, like every controller here: the projection, the validation and
 * the ownership rules all live in BudgetService, which the API will call
 * unchanged when it arrives.
 */
final class BudgetController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        private readonly BudgetService $budgets,
        private readonly CategoryService $categories,
        private readonly MembershipRepository $memberships,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        return $this->render($request, $response, 'budgets/index.twig', [
            'progress' => $this->budgets->progress($scope),
        ]);
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'budgets/form.twig', $this->formData($request, [
            'period' => BudgetPeriod::Monthly->value,
            'currency' => $this->settings->baseCurrency(),
            'is_active' => '1',
        ]));
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->budgets->create($this->scope($request), $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'budgets/form.twig',
                $this->formData($request, $body, $exception->errors()),
            );
        }

        $this->flash('success', 'Budget created.');

        return $this->redirectAfterWrite($request, $response, '/budgets');
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $budget = $this->budgets->find($this->scope($request), (int) $id);
        if ($budget === null) {
            throw $this->notFound($request);
        }

        return $this->render($request, $response, 'budgets/form.twig', $this->formData($request, [
            'id' => $budget->id,
            'name' => $budget->name,
            'category_id' => $budget->categoryId,
            'period' => $budget->period->value,
            'amount' => $budget->amount->toDecimalString(),
            'currency' => $budget->amount->currency,
            'warn_threshold_percent' => $budget->warnThresholdPercent,
            'owner_user_id' => $budget->ownerUserId,
            'is_active' => $budget->isActive ? '1' : '0',
        ], [], $budget->id));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->budgets->update($this->scope($request), (int) $id, $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'budgets/form.twig',
                $this->formData($request, $body, $exception->errors(), (int) $id),
            );
        }

        $this->flash('success', 'Budget saved.');

        return $this->redirectAfterWrite($request, $response, '/budgets');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->budgets->delete($this->scope($request), (int) $id);
        $this->flash('success', 'Budget deleted.');

        return $this->redirectAfterWrite($request, $response, '/budgets');
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function formData(
        ServerRequestInterface $request,
        array $values,
        array $errors = [],
        ?int $id = null,
    ): array {
        $scope = $this->scope($request);

        return [
            'values' => $values,
            'errors' => $errors,
            'budget_id' => $id,
            'categories' => $this->categories->all($scope),
            'periods' => BudgetPeriod::cases(),
            'currencies' => Currency::common(),
            'members' => $scope->hasHousehold()
                ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
                : [],
        ];
    }
}
