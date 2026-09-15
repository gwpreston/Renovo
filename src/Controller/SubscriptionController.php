<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\BillingCycle;
use App\Domain\Currency;
use App\Domain\NoticePeriod;
use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionType;
use App\Repository\MembershipRepository;
use App\Security\ScopeViolationException;
use App\Security\SessionInterface;
use App\Service\CategoryService;
use App\Service\LogoStorage;
use App\Service\BulkActionService;
use App\Service\CatchUpService;
use App\Service\SubscriptionService;
use App\Service\TagService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * Subscription CRUD. Every mutating action here is additionally guarded by
 * RequirePermissionMiddleware on its route — see config/routes.php.
 */
final class SubscriptionController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        private readonly SubscriptionService $subscriptions,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly MembershipRepository $memberships,
        private readonly LogoStorage $logos,
        private readonly CatchUpService $catchUp,
        private readonly BulkActionService $bulkActions,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $filter = SubscriptionFilter::fromQueryParams($request->getQueryParams());

        $this->catchUp->run($scope);

        $total = $this->subscriptions->count($scope, $filter);
        $items = $this->subscriptions->list($scope, $filter);

        $data = [
            'subscriptions' => $items,
            'filter' => $filter,
            'total' => $total,
            'page_count' => max(1, (int) ceil($total / $filter->perPage)),
            'categories' => $this->categories->all($scope),
            'tags' => $this->tags->all($scope),
            'members' => $scope->hasHousehold()
                ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
                : [],
            'currencies' => $this->subscriptions->currenciesInUse($scope),
            // The filter offers only currencies actually in use; converting to
            // one needs the full pick-list.
            'all_currencies' => Currency::common(),
            'types' => SubscriptionType::cases(),
            'bulk_actions' => BulkActionService::actions(),
        ];

        // htmx asks for just the table when filtering, sorting or paging.
        return $this->renderMaybeFragment(
            $request,
            $response,
            'subscriptions/index.twig',
            'subscriptions/_list.twig',
            $data,
        );
    }

    /**
     * Apply one action to a selection of subscriptions.
     *
     * Guarded by the BulkEdit permission on the route, and by the scoping layer
     * on every individual write — a selection containing ids the caller cannot
     * write simply changes fewer rows, and says so.
     */
    public function bulk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $changed = $this->bulkActions->apply($this->scope($request), $body);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $message) {
                $this->flash('error', $message);
            }

            return $this->redirectAfterWrite($request, $response, '/subscriptions');
        }

        $this->flash('success', sprintf(
            '%d subscription%s updated.',
            $changed,
            $changed === 1 ? '' : 's',
        ));

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'subscriptions/form.twig', $this->formData($request, [
            'subscription_type' => SubscriptionType::Recurring->value,
            'billing_cycle' => BillingCycle::Monthly->value,
            'is_active' => '1',
            'next_payment_date' => date('Y-m-d'),
        ]));
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        try {
            $body['logo_path'] = $this->logos->store($this->uploadedLogo($request)) ?? '';
            $this->subscriptions->create($scope, $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'subscriptions/form.twig',
                $this->formData($request, $body, $exception->errors()),
            );
        }

        $this->flash('success', 'Subscription added.');

        // Back to the list, the same place a save from the edit form lands.
        // The new row is visible there, which is the confirmation the user is
        // actually after; dropping them back into a form they have just
        // finished filling in reads as though something went wrong.
        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $subscription = $this->subscriptions->find($scope, (int) $id);

        if ($subscription === null) {
            // Out of scope and non-existent are deliberately the same answer:
            // a 403 here would confirm that somebody else's row exists.
            throw $this->notFound($request);
        }

        return $this->render($request, $response, 'subscriptions/form.twig', $this->formData($request, [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'price' => $subscription->price->toDecimalString(),
            'currency' => $subscription->price->currency,
            'subscription_type' => $subscription->type->value,
            'billing_cycle' => $subscription->billingCycle?->value,
            'cycle_days' => $subscription->cycleDays,
            'next_payment_date' => $subscription->nextPaymentDate?->format('Y-m-d'),
            'start_date' => $subscription->startDate?->format('Y-m-d'),
            'is_trial' => $subscription->isTrial ? '1' : '0',
            'trial_end_date' => $subscription->trialEndDate?->format('Y-m-d'),
            'converts_to_price' => $subscription->convertsToPrice?->toDecimalString(),
            'converts_to_billing_cycle' => $subscription->convertsToBillingCycle?->value,
            'converts_to_cycle_days' => $subscription->convertsToCycleDays,
            'notice_period_amount' => $subscription->noticePeriod->amount,
            'notice_period_unit' => $subscription->noticePeriod->unit,
            // An empty override is a real setting — "never remind me about this
            // one" — so it renders as the word rather than as a blank box that
            // would read as "use my usual schedule".
            'reminder_days' => $subscription->reminderDays === '' ? 'none' : $subscription->reminderDays,
            'category_id' => $subscription->categoryId,
            'owner_user_id' => $subscription->ownerUserId,
            'payer_user_id' => $subscription->payerUserId,
            'notes' => $subscription->notes,
            'is_active' => $subscription->isActive ? '1' : '0',
            'logo_path' => $subscription->logoPath,
            'tags' => implode(', ', array_map(static fn ($tag): string => $tag->name, $subscription->tags)),
        ], [], $subscription->id));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        try {
            $uploaded = $this->logos->store($this->uploadedLogo($request));
            if ($uploaded !== null) {
                $body['logo_path'] = $uploaded;
            }

            $this->subscriptions->update($scope, (int) $id, $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'subscriptions/form.twig',
                $this->formData($request, $body, $exception->errors(), (int) $id),
            );
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'Subscription saved.');

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);

        try {
            $subscription = $this->subscriptions->find($scope, (int) $id);
            $this->subscriptions->delete($scope, (int) $id);
            $this->logos->delete($subscription?->logoPath);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'Subscription deleted.');

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        $subscription = $this->subscriptions->find($scope, (int) $id);

        if ($subscription === null) {
            throw $this->notFound($request);
        }

        try {
            $this->subscriptions->setActive($scope, $subscription->id, !$subscription->isActive);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', $subscription->isActive ? 'Subscription paused.' : 'Subscription resumed.');

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    private function uploadedLogo(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $logo = $files['logo'] ?? null;

        return $logo instanceof UploadedFileInterface ? $logo : null;
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
            'subscription_id' => $id,
            'categories' => $this->categories->all($scope),
            'members' => $scope->hasHousehold()
                ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
                : [],
            'currencies' => Currency::common(),
            'cycles' => BillingCycle::cases(),
            'types' => SubscriptionType::cases(),
            'notice_units' => NoticePeriod::units(),
        ];
    }
}
