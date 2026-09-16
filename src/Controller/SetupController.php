<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Application\Middleware\AuthenticationMiddleware;
use App\Repository\MembershipRepository;
use App\Security\SessionInterface;
use App\Notification\NotifierRegistry;
use App\Service\InstanceSettingsService;
use App\Service\Notification\NotificationSettingsService;
use App\Service\SetupService;
use App\Service\ValidationException;
use App\Support\Clock;
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
        Translator $translator,
        private readonly SetupService $setup,
        private readonly MembershipRepository $memberships,
        private readonly NotificationSettingsService $notifications,
        private readonly NotifierRegistry $notifiers,
        private readonly InstanceSettingsService $instance,
        private readonly Clock $clock,
        private readonly string $smtpHost = '',
        private readonly string $mailFrom = '',
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function showForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'setup/wizard.twig', $this->formData([]));
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

        $this->flash('success', 'flash.setup_ready');

        // Straight into step two rather than to the dashboard. Notifications
        // are the one part of this application that is worthless if nobody ever
        // configures it, and the moment somebody is already setting the
        // instance up is the moment they are most willing to.
        return $this->redirect($response, '/setup/notifications');
    }

    /**
     * Step two: a channel, and a test message to prove it works.
     *
     * Sending a real message is the only part of this that matters. A form that
     * accepts a Gotify token and says "saved" has verified nothing; the first
     * time the operator learns the token was wrong would otherwise be the
     * renewal they missed.
     */
    public function showNotifications(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->user($request);

        if (!$user->isInstanceAdmin || $this->instance->isNotificationSetupComplete()) {
            return $this->redirect($response, '/');
        }

        return $this->render($request, $response, 'setup/notifications.twig', $this->notificationStepData($user->id));
    }

    public function addNotificationChannel(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->user($request);
        $body = $this->body($request);

        $strings = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $strings[$key] = (string) $value;
            }
        }

        try {
            $this->notifications->createChannel($user->id, $strings);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'setup/notifications.twig',
                $this->notificationStepData($user->id, $exception->errors(), $body),
            );
        }

        $this->flash('success', 'flash.setup_channel_added');

        return $this->redirectAfterWrite($request, $response, '/setup/notifications');
    }

    public function finishNotifications(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->instance->markNotificationSetupComplete($this->clock->now()->format('Y-m-d H:i:s'));

        $this->flash('success', 'flash.setup_finished');

        return $this->redirectAfterWrite($request, $response, '/');
    }

    /**
     * @param array<string, \App\Service\ValidationError> $errors
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function notificationStepData(int $userId, array $errors = [], array $submitted = []): array
    {
        return [
            'notifiers' => $this->notifiers->all(),
            'channels' => $this->notifications->channels($userId),
            // Shown rather than described. The step's job is to make the mail
            // relay visible, and "if one is configured" leaves the operator to
            // work out for themselves whether it is.
            'smtp_host' => $this->smtpHost,
            'mail_from' => $this->mailFrom,
            'errors' => $errors,
            'submitted' => $submitted,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, \App\Service\ValidationError> $errors
     * @return array<string, mixed>
     */
    private function formData(array $values, array $errors = []): array
    {
        return [
            'values' => $values,
            'errors' => $errors,
        ];
    }
}
