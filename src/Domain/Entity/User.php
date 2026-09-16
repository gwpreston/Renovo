<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Density;
use App\Domain\LandingView;
use App\Domain\Theme;
use App\Domain\WeekStart;
use DateTimeImmutable;

final class User
{
    /**
     * The display preferences trail the rest with defaults deliberately: they
     * are additions to an entity that predates them, and every one of them has
     * a sensible value for an account that has never opened the settings page.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $passwordHash,
        public readonly bool $isInstanceAdmin,
        public readonly ?DateTimeImmutable $emailVerifiedAt,
        public readonly string $theme,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $webauthnHandle = null,
        /** Empty means "whatever this instance is configured for". */
        public readonly string $locale = '',
        public readonly int $weekStart = 1,
        public readonly string $density = 'comfortable',
        public readonly string $landingView = 'dashboard',
    ) {
    }

    public function isVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function themePreference(): Theme
    {
        return Theme::fromString($this->theme);
    }

    public function densityPreference(): Density
    {
        return Density::fromString($this->density);
    }

    public function weekStartPreference(): WeekStart
    {
        return WeekStart::fromInt($this->weekStart);
    }

    public function landingViewPreference(): LandingView
    {
        return LandingView::fromString($this->landingView);
    }
}
