<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Density;
use App\Domain\LandingView;
use App\Domain\Theme;
use App\Domain\WeekStart;
use App\I18n\Locales;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\AvatarStorage;
use App\Service\DashboardLayoutService;
use App\Service\UserPreferencesService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The account's own page: who one person is, how they get in, and how Renovo
 * looks and behaves for them.
 *
 * Separate from Settings because the two answer different questions. Nothing
 * here needs a permission — every value changes what one account sees and
 * nothing that anybody else does — whereas most of Settings is a household or
 * an instance deciding something on everybody's behalf.
 *
 * `/profile` and `/profile/account` used to be two screens, reached from two
 * corners of the same shell, and the line between them was not one a reader
 * had to draw: both acted on the id in the session and neither needed a
 * permission. They are one page now. This controller renders it and
 * `AccountController` still answers the name, address, password and picture
 * forms on it — one page, two controllers, because "how this looks" and "who
 * is looking" remain different subjects however they are laid out.
 */
final class ProfileController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly UserPreferencesService $preferences,
        private readonly DashboardLayoutService $dashboard,
        private readonly Locales $locales,
        // For the picture card's size hint. The uploads themselves are
        // AccountController's, and so is every other write this page's forms
        // make; what this controller owns is the page they are drawn on.
        private readonly AvatarStorage $avatarStorage,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'profile/index.twig', [
            'themes' => Theme::cases(),
            'densities' => Density::cases(),
            'week_starts' => WeekStart::cases(),
            'landing_views' => LandingView::cases(),
            'locale_choices' => $this->locales->choices(),
            'dashboard_layout' => $this->dashboard->forUser($this->user($request)->id),
            'avatar_max_kilobytes' => max(1, intdiv($this->avatarStorage->maxBytes(), 1024)),
        ]);
    }

    /**
     * The theme switch, which appears on every page and therefore submits on
     * its own rather than as part of the profile form.
     */
    public function updateTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $theme = is_scalar($body['theme'] ?? null) ? (string) $body['theme'] : null;

        $this->preferences->updateTheme($this->user($request)->id, $theme);

        // Back where they were: the switch is in the navigation bar, so
        // sending them to the profile page from an arbitrary page would be a
        // navigation they did not ask for.
        $referer = $request->getHeaderLine('Referer');
        $target = $referer !== '' ? $this->samePathAsUs($request, $referer) : '/profile';

        return $this->redirectAfterWrite($request, $response, $target);
    }

    public function updatePreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $userId = $this->user($request)->id;

        $this->preferences->update($userId, $body);

        $this->dashboard->update(
            $userId,
            is_array($body['card_position'] ?? null) ? $body['card_position'] : [],
            is_array($body['card_visible'] ?? null) ? $body['card_visible'] : [],
        );

        $this->flash('success', 'flash.preferences_saved');

        return $this->redirectAfterWrite($request, $response, '/profile');
    }

    /**
     * A Referer, reduced to a path on this instance.
     *
     * Never used as a redirect target as it arrived: an absolute URL in that
     * header is attacker-controllable, and handing it to a Location header is
     * an open redirect. Only the path survives, and only when the host matches.
     */
    private function samePathAsUs(ServerRequestInterface $request, string $referer): string
    {
        $parts = parse_url($referer);
        if ($parts === false) {
            return '/profile';
        }

        $host = $parts['host'] ?? null;
        if ($host !== null && $host !== $request->getUri()->getHost()) {
            return '/profile';
        }

        $path = $parts['path'] ?? '/';

        return str_starts_with($path, '/') ? $path : '/profile';
    }
}
