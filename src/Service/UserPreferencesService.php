<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\DashboardView;
use App\Domain\Density;
use App\Domain\LandingView;
use App\Domain\Palette;
use App\Domain\Theme;
use App\Domain\WeekStart;
use App\I18n\Locales;
use App\Repository\UserRepository;

/**
 * A user's own display preferences: theme, palette, language, week start, list
 * density and where they land.
 *
 * Every value is validated here rather than in the controller, which is the
 * difference between a preference and an arbitrary string in a column: each
 * one resolves through its enum, and anything unrecognised becomes the
 * default instead of being stored and puzzled over later.
 *
 * None of these needs a permission. They change what one account sees and
 * nothing that anybody else does, so a Viewer may set them for the same reason
 * they may configure their own reminders.
 */
final class UserPreferencesService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Locales $locales,
    ) {
    }

    /**
     * The theme on its own — the switch in the navigation bar, which must not
     * disturb any other preference.
     */
    public function updateTheme(int $userId, ?string $theme): Theme
    {
        $resolved = Theme::fromString($theme);

        $this->users->updatePreferences($userId, ['theme' => $resolved->value]);

        return $resolved;
    }

    /**
     * Which dashboard the account opens on, from the toggle at its top.
     *
     * Coerced rather than rejected, like the theme: the stored value only ever
     * chooses between two pages the member may already see, so an unknown one
     * is harmlessly Overview.
     */
    public function updateDashboardView(int $userId, ?string $view): DashboardView
    {
        $resolved = DashboardView::fromString($view);

        $this->users->updatePreferences($userId, ['dashboard_view' => $resolved->value]);

        return $resolved;
    }

    /**
     * List density on its own, from the toggle on the subscriptions toolbar.
     * Coerced like the theme: either value only changes padding.
     */
    public function updateDensity(int $userId, ?string $density): Density
    {
        $resolved = Density::fromString($density);

        $this->users->updatePreferences($userId, ['density' => $resolved->value]);

        return $resolved;
    }

    /**
     * The profile's appearance form, which saves on each change with script
     * and all at once without it.
     *
     * A field that was not submitted is left as it is. With script the form
     * posts on every change, and the language control is not drawn at all
     * while one catalogue exists; if absence meant "the default", either would
     * quietly reset a choice the member made.
     *
     * The palette is the one value *rejected* rather than coerced. Every other
     * preference here becomes its default when the value is not one it knows;
     * this one ends up in an attribute on every page's root element, so an
     * unknown value is a tampered form, and the answer to that is an error —
     * not a silent reset. It is refused after the rest are written, so a bad
     * palette does not throw away the theme or week start sent beside it.
     *
     * Returns what is now stored for the three values the page wears, so the
     * caller can restyle the page in place without a second read.
     *
     * @param array<string, mixed> $input
     * @return array{theme: ?string, palette: ?string, density: ?string}
     * @throws ValidationException
     */
    public function update(int $userId, array $input): array
    {
        $changes = [];

        if (array_key_exists('theme', $input)) {
            $changes['theme'] = Theme::fromString($this->string($input, 'theme'))->value;
        }
        if (array_key_exists('locale', $input)) {
            $changes['locale'] = $this->locale($this->string($input, 'locale'));
        }
        if (array_key_exists('week_start', $input)) {
            $changes['week_start'] = WeekStart::fromInt($this->int($input, 'week_start'))->value;
        }
        if (array_key_exists('density', $input)) {
            $changes['density'] = Density::fromString($this->string($input, 'density'))->value;
        }
        if (array_key_exists('landing_view', $input)) {
            $changes['landing_view'] = LandingView::fromString($this->string($input, 'landing_view'))->value;
        }

        $palette = null;
        $paletteRefused = false;
        if (array_key_exists('palette', $input)) {
            $palette = Palette::tryFrom($this->string($input, 'palette') ?? '');
            if ($palette === null) {
                $paletteRefused = true;
            } else {
                $changes['palette'] = $palette->value;
            }
        }

        if ($changes !== []) {
            $this->users->updatePreferences($userId, $changes);
        }

        if ($paletteRefused) {
            throw ValidationException::field('palette', 'error.palette.unknown');
        }

        return [
            'theme' => $changes['theme'] ?? null,
            'palette' => $palette?->value,
            'density' => $changes['density'] ?? null,
        ];
    }

    /**
     * An empty locale is a real answer, not a missing one: it means "whatever
     * this instance is set to", which is what an account wants when the
     * operator changes the instance language.
     */
    private function locale(?string $locale): string
    {
        if ($locale === null || $locale === '' || !$this->locales->isAvailable($locale)) {
            return '';
        }

        return $locale;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function string(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function int(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
