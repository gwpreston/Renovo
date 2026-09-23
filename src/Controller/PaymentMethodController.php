<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\ScopeViolationException;
use App\Security\SessionInterface;
use App\Service\PaymentMethodService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * The household's payment methods: the list, and the five things a manager can
 * do to it. Gated by the category permission at the route, because the list is
 * the same kind of household metadata and the same people look after it.
 */
final class PaymentMethodController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly PaymentMethodService $methods,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page($request, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        try {
            $this->methods->create(
                $scope,
                $this->string($body, 'name'),
                $this->colour($body),
                $this->uploadedLogo($request),
            );
        } catch (ValidationException $exception) {
            return $this->page($request, $response->withStatus(422), $exception->errors(), $body);
        }

        $this->flash('success', 'flash.payment_method_added');

        return $this->redirectAfterWrite($request, $response, '/payment-methods');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        try {
            $this->methods->update(
                $scope,
                (int) $id,
                $this->string($body, 'name'),
                $this->colour($body),
                $this->uploadedLogo($request),
            );
        } catch (ValidationException $exception) {
            return $this->page($request, $response->withStatus(422), $exception->errors(), [], (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.payment_method_saved');

        return $this->redirectAfterWrite($request, $response, '/payment-methods');
    }

    public function clearLogo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        try {
            $this->methods->clearLogo($this->scope($request), (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.payment_method_logo_cleared');

        return $this->redirectAfterWrite($request, $response, '/payment-methods');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $this->methods->delete($this->scope($request), (int) $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.payment_method_deleted');

        return $this->redirectAfterWrite($request, $response, '/payment-methods');
    }

    /**
     * The default list, for a household that has none — one that existed
     * before payment methods did, or that removed every one of them. The
     * service refuses to top up a list that is not empty, so a second press
     * of the button does nothing.
     */
    public function seedDefaults(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $user = $this->user($request);

        if ($this->methods->seedDefaults($scope, $user->locale) !== []) {
            $this->flash('success', 'flash.payment_methods_defaults_added');
        }

        return $this->redirectAfterWrite($request, $response, '/payment-methods');
    }

    /**
     * @param array<string, \App\Service\ValidationError> $errors
     * @param array<string, mixed> $values
     */
    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $errors = [],
        array $values = [],
        ?int $failedId = null,
    ): ResponseInterface {
        return $this->render($request, $response, 'payment_methods/index.twig', [
            'payment_methods' => $this->methods->all($this->scope($request)),
            'errors' => $errors,
            'values' => $values,
            'failed_id' => $failedId,
        ]);
    }

    private function uploadedLogo(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $logo = $request->getUploadedFiles()['logo'] ?? null;

        return $logo instanceof UploadedFileInterface ? $logo : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $key): string
    {
        return is_scalar($body[$key] ?? null) ? (string) $body[$key] : '';
    }

    /**
     * The colour the form asked for, or null for "automatic" — which is a
     * checkbox, because a colour input always submits some colour.
     *
     * @param array<string, mixed> $body
     */
    private function colour(array $body): ?string
    {
        if (($body['colour_auto'] ?? null) === '1') {
            return null;
        }

        return is_scalar($body['colour'] ?? null) ? (string) $body['colour'] : null;
    }
}
