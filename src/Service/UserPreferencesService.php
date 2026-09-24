<?php

declare(strict_types=1);

namespace App\Service;

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
     * The palette, on its own form.
     *
     * The one preference that is *rejected* rather than coerced. Every other
     * preference here quietly becomes its default when the value is not one it
     * knows; this one ends up in an attribute on every page's root element, so
     * an unknown value is a tampered form, and the answer to a tampered form
     * is an error and no write — not a silent reset of a choice the member
     * really did make.
     */
    public function updatePalette(int $userId, ?string $palette): Palette
    {
        $resolved = Palette::tryFrom($palette ?? '');

        if ($resolved === null) {
            throw ValidationException::field('palette', 'error.palette.unknown');
        }

        $this->users->updatePreferences($userId, ['palette' => $resolved->value]);

        return $resolved;
    }

    /**
     * The settings form, which submits all of them at once.
     *
     * @param array<string, mixed> $input
     */
    public function update(int $userId, array $input): void
    {
        $this->users->updatePreferences($userId, [
            'theme' => Theme::fromString($this->string($input, 'theme'))->value,
            'locale' => $this->locale($this->string($input, 'locale')),
            'week_start' => WeekStart::fromInt($this->int($input, 'week_start'))->value,
            'density' => Density::fromString($this->string($input, 'density'))->value,
            'landing_view' => LandingView::fromString($this->string($input, 'landing_view'))->value,
        ]);
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
