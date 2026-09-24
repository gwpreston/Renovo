<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Role;
use App\I18n\Translator;
use App\Repository\HouseholdRepository;
use App\Security\SessionInterface;
use App\Service\HouseholdMemberService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The household's people.
 *
 * Thin, like every controller here: it reads the form, calls one service
 * method and redirects. The interesting decisions — who may do this, whether
 * the household would be left without an Owner, what happens to a departing
 * member's rows — are all in `HouseholdMemberService`, so the API phase that
 * eventually exposes these actions gets them for free.
 *
 * The routes are behind `Permission::ManageHousehold`, which is where a Viewer
 * or an Editor is stopped. The service asks the scope the same question again
 * before it does anything, because a permission on a route is a fact about the
 * route and the rule belongs to the operation.
 */
final class MemberController extends Controller
{
    private const TEMPORARY_PASSWORD_KEY = 'member_temporary_password';
    private const TEMPORARY_PASSWORD_NAME_KEY = 'member_temporary_password_for';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly HouseholdMemberService $members,
        private readonly HouseholdRepository $households,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        return $this->render($request, $response, 'settings/members.twig', [
            'members' => $this->members->list($scope),
            'household' => $scope->hasHousehold() ? $this->households->findById((int) $scope->householdId) : null,
            'roles' => Role::assignable(),
            'is_isolated' => $scope->restrictsReadsToOwner(),
            // Shown once and never again. It is carried across the redirect in
            // the session and taken straight back out, so a reload of the page
            // does not show it a second time and nothing persists it.
            'temporary_password' => $this->takeOnce(self::TEMPORARY_PASSWORD_KEY),
            'temporary_password_for' => $this->takeOnce(self::TEMPORARY_PASSWORD_NAME_KEY),
        ]);
    }

    /**
     * Read a one-shot session value and remove it in the same breath.
     */
    private function takeOnce(string $key): ?string
    {
        $value = $this->session->get($key);
        $this->session->remove($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function add(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $temporary = $this->members->add(
                $this->user($request),
                $this->scope($request),
                is_scalar($body['display_name'] ?? null) ? (string) $body['display_name'] : '',
                is_scalar($body['email'] ?? null) ? (string) $body['email'] : '',
                Role::tryFrom(is_scalar($body['role'] ?? null) ? (string) $body['role'] : '') ?? Role::Viewer,
                ($body['without_email'] ?? '') === '1',
            );

            if ($temporary !== null) {
                $this->session->set(self::TEMPORARY_PASSWORD_KEY, $temporary);
                $this->session->set(
                    self::TEMPORARY_PASSWORD_NAME_KEY,
                    is_scalar($body['display_name'] ?? null) ? trim((string) $body['display_name']) : '',
                );
                $this->flash('success', 'flash.member_added_temporary');
            } else {
                $this->flash('success', 'flash.member_invited');
            }
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->done($request, $response);
    }

    public function resendInvite(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        return $this->act($request, $response, 'flash.member_invite_resent', function () use ($request, $id): void {
            $this->members->resendInvite($this->user($request), $this->scope($request), (int) $id);
        });
    }

    public function sendPasswordReset(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        return $this->act($request, $response, 'flash.member_reset_sent', function () use ($request, $id): void {
            $this->members->sendPasswordReset($this->user($request), $this->scope($request), (int) $id);
        });
    }

    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        return $this->act($request, $response, 'flash.member_revoked', function () use ($request, $id): void {
            $this->members->revokeLogin($this->user($request), $this->scope($request), (int) $id);
        });
    }

    public function restore(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        return $this->act($request, $response, 'flash.member_restored', function () use ($request, $id): void {
            $this->members->restoreLogin($this->user($request), $this->scope($request), (int) $id);
        });
    }

    public function changeRole(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $body = $this->body($request);
        $role = Role::tryFrom(is_scalar($body['role'] ?? null) ? (string) $body['role'] : '');

        if ($role === null) {
            $this->flash('error', 'error.member.role_invalid');

            return $this->done($request, $response);
        }

        return $this->act(
            $request,
            $response,
            'flash.member_role_changed',
            function () use ($request, $id, $role): void {
                $this->members->changeRole($this->user($request), $this->scope($request), (int) $id, $role);
            },
        );
    }

    /**
     * The confirmation step. In ISOLATED mode it asks what should happen to
     * the member's private rows; in SHARED there is nothing to ask, because
     * the household could already see them.
     */
    public function confirmRemoval(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $userId = (int) $id;

        $member = null;
        foreach ($this->members->list($scope) as $candidate) {
            if ($candidate->userId === $userId) {
                $member = $candidate;
                break;
            }
        }

        if ($member === null) {
            throw $this->notFound($request);
        }

        return $this->render($request, $response, 'settings/member_remove.twig', [
            'member' => $member,
            'is_isolated' => $scope->restrictsReadsToOwner(),
            'owned_rows' => $this->members->ownedRowCount($scope, $userId),
            'private_rows' => $this->members->privateRowCount($scope, $userId),
        ]);
    }

    public function remove(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $body = $this->body($request);
        $deleteData = ($body['data'] ?? 'reassign') === 'delete';
        $deletePrivate = ($body['private_data'] ?? 'reassign') === 'delete';

        return $this->act(
            $request,
            $response,
            'flash.member_removed',
            function () use ($request, $id, $deleteData, $deletePrivate): void {
                $this->members->remove(
                    $this->user($request),
                    $this->scope($request),
                    (int) $id,
                    $deleteData,
                    $deletePrivate,
                );
            },
        );
    }

    /**
     * Run one service call, flash the outcome, and go back to the list.
     *
     * Six of the actions here differ in one line each; writing the try/catch
     * out six times would be six chances to forget the catch and answer a
     * refused last-Owner demotion with a 500.
     */
    private function act(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $successKey,
        callable $work,
    ): ResponseInterface {
        try {
            $work();
            $this->flash('success', $successKey);
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->done($request, $response);
    }

    private function done(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirectAfterWrite($request, $response, '/settings/members');
    }
}
