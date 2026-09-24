<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\DashboardView;
use App\Domain\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Which dashboard an account opens on, and what the greeting calls them.
 */
final class DashboardViewTest extends TestCase
{
    public function testAnAccountThatNeverChoseOpensOnOverview(): void
    {
        self::assertSame(DashboardView::Overview, DashboardView::fromString(null));
        self::assertSame(DashboardView::Overview, DashboardView::fromString('tampered'));
        self::assertSame(DashboardView::Household, DashboardView::fromString('household'));

        self::assertSame(DashboardView::Overview, $this->user('Sam')->dashboardViewPreference());
        self::assertSame(
            DashboardView::Household,
            $this->user('Sam', 'household')->dashboardViewPreference(),
        );
    }

    public function testTheGreetingUsesTheDisplayNameUpToItsFirstSpace(): void
    {
        self::assertSame('Sarah', $this->user('Sarah Jenkins')->firstName());
        self::assertSame('Mary', $this->user('  Mary Ann Evans ')->firstName());
        self::assertSame('Prince', $this->user('Prince')->firstName());
    }

    private function user(string $name, ?string $view = null): User
    {
        return new User(
            7,
            'sam@example.com',
            $name,
            'hash',
            false,
            null,
            'system',
            new DateTimeImmutable(),
            dashboardView: $view,
        );
    }
}
