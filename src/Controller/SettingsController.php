<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\TokenAbility;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\ExchangeRate\RateProviderException;
use App\Service\ExchangeRateService;
use App\Service\HouseholdSettingsService;
use App\Service\InstanceAdminService;
use App\Service\SettingsScreenService;
use App\Service\TrustedHostService;
use App\Service\ValidationException;
use App\Support\DateFormatter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Settings: the things somebody decides on everybody else's behalf, as three
 * tabs (Phase 28).
 *
 *  - **General** (`/settings`) — the household's name, the instance's base
 *    currency and exchange rates, and the household's categories, tags and
 *    payment methods.
 *  - **Data & integrations** (`/settings/data`) — import, backup and restore,
 *    export, API tokens, recent activity and the calendar feed.
 *  - **Instance** (`/settings/instance`) — registration, isolation, trusted
 *    hosts, demo mode and the server's own status. Instance administrators
 *    only; the route answers 403 to everybody else.
 *
 * The tabs are links, not script: each is a page of its own with its own URL,
 * which is what makes the Instance tab's 403 a route's answer rather than a
 * hidden panel.
 *
 * Each section is gated where it is drawn and again at the route its form
 * posts to. One account's own preferences are not here — they are
 * `ProfileController`'s — and nor are one account's notifications, which are
 * `NotificationController`'s (decision 13).
 *
 * The currency and the rates sit on General because that is where a reader
 * looks for them, but they are the instance's, not the household's: only an
 * instance administrator may change them, through a form of their own. Every
 * other reader sees them as facts.
 */
final class SettingsController extends Controller
{
    public const GENERAL = '/settings';
    public const DATA = '/settings/data';
    public const INSTANCE = '/settings/instance';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly SettingsScreenService $screen,
        private readonly ExchangeRateService $rates,
        private readonly TrustedHostService $trustedHosts,
        private readonly HouseholdSettingsService $householdSettings,
        private readonly InstanceAdminService $instanceAdmin,
        private readonly DateFormatter $dates,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function general(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render(
            $request,
            $response,
            'settings/general.twig',
            $this->screen->general($this->scope($request)),
        );
    }

    public function data(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // A token's secret is shown once, on the page the issue lands on, and
        // then forgotten: taken from the session here and removed in the same
        // breath, so a reload does not bring it back.
        $issued = $this->session->get(ApiTokenController::FLASH_NEW_TOKEN);
        $this->session->remove(ApiTokenController::FLASH_NEW_TOKEN);

        return $this->render($request, $response, 'settings/data.twig', [
            ...$this->screen->data($this->user($request), $this->scope($request)),
            'issued_token' => is_string($issued) ? $issued : null,
            'abilities' => TokenAbility::cases(),
            'api_base' => rtrim((string) $request->getUri()->withPath('')->withQuery(''), '/') . '/api/v1',
        ]);
    }

    public function instance(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'settings/instance.twig', $this->screen->instance());
    }

    public function updateHousehold(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        if ($scope->hasHousehold()) {
            $this->householdSettings->rename(
                $this->user($request),
                (int) $scope->householdId,
                is_scalar($body['name'] ?? null) ? (string) $body['name'] : '',
            );
        }

        $this->flash('success', 'flash.household_saved');

        return $this->redirectAfterWrite($request, $response, self::GENERAL . '#household');
    }

    /**
     * The base currency and the rate provider: the General tab's instance-wide
     * settings, posted only by an instance administrator.
     *
     * Either form may post; each names only its own fields, and a setting that
     * is not posted is left as it is.
     */
    public function updateCurrency(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $input = [];
        foreach (['base_currency', 'rate_provider', 'rate_provider_key'] as $field) {
            if (is_scalar($body[$field] ?? null)) {
                $input[$field] = (string) $body[$field];
            }
        }
        $input['clear_rate_provider_key'] = ($body['clear_rate_provider_key'] ?? '') === '1';

        $this->instanceAdmin->apply($this->user($request), $input);
        $this->flash('success', 'flash.instance_saved');

        $section = isset($input['base_currency']) && !isset($input['rate_provider']) ? '#household' : '#exchange-rates';

        return $this->redirectAfterWrite($request, $response, self::GENERAL . $section);
    }

    /**
     * Refresh now: the scheduled refresh, run on request, and refused while a
     * failed attempt is inside its back-off.
     */
    public function refreshRates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $count = $this->rates->refreshNow();

            if ($count === null) {
                // Formatted as the page's own hint formats it, so the two
                // never name different times.
                $until = $this->rates->retryAfter();
                $this->flash('error', 'flash.rates_backing_off', [
                    'time' => $until === null ? '' : $this->dates->format($until, 'HH:mm'),
                ]);
            } else {
                $this->flash('success', 'flash.rates_refreshed', ['count' => $count]);
            }
        } catch (RateProviderException $exception) {
            $this->flash('error', 'flash.rates_refresh_failed', ['reason' => $exception->getMessage()]);
        }

        return $this->redirectAfterWrite($request, $response, self::GENERAL . '#exchange-rates');
    }

    public function updateInstance(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        // Only what the Instance tab's form carries. The currency and the
        // rates have a form of their own on General; not naming them here is
        // what leaves them alone.
        $input = [];
        if (is_scalar($body['isolation_mode'] ?? null)) {
            $input['isolation_mode'] = (string) $body['isolation_mode'];
        }
        if (array_key_exists('allow_registration', $body)) {
            $input['allow_registration'] = $body['allow_registration'] === '1';
        }
        if (array_key_exists('demo_mode', $body)) {
            $input['demo_mode'] = $body['demo_mode'] === '1';
        }

        $this->instanceAdmin->apply($this->user($request), $input);

        $this->flash('success', 'flash.instance_saved');

        return $this->redirectAfterWrite($request, $response, self::INSTANCE);
    }

    /**
     * Add a destination the SSRF guard will permit despite it being private.
     *
     * Guarded by instance administration at the route, which is the level this
     * belongs at: the answer to "may this server connect to 192.168.1.0/24" is
     * a fact about the network the instance sits on, not a preference of
     * whoever happens to be configuring a webhook.
     */
    public function addTrustedHost(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->trustedHosts->add(
                is_scalar($body['pattern'] ?? null) ? (string) $body['pattern'] : '',
                is_scalar($body['note'] ?? null) ? (string) $body['note'] : null,
                $this->user($request),
            );
            $this->flash('success', 'flash.trusted_host_added');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, self::INSTANCE . '#trusted-hosts');
    }

    public function deleteTrustedHost(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->trustedHosts->remove((int) $id, $this->user($request));
        $this->flash('success', 'flash.trusted_host_removed');

        return $this->redirectAfterWrite($request, $response, self::INSTANCE . '#trusted-hosts');
    }
}
