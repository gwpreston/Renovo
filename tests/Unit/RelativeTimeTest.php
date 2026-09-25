<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\I18n\LocaleContext;
use App\Support\DateFormatter;
use App\Support\FrozenClock;
use App\Support\RelativeTime;
use App\Tests\Support\TestTranslator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * "Last seen", as a phrase for the last month and a date before that.
 */
final class RelativeTimeTest extends TestCase
{
    private RelativeTime $relative;

    protected function setUp(): void
    {
        $this->relative = new RelativeTime(
            FrozenClock::at('2026-09-25 12:00:00'),
            TestTranslator::create(),
            new DateFormatter(new LocaleContext('en_GB')),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function moments(): array
    {
        return [
            'seconds ago' => ['2026-09-25 11:59:30', 'Just now'],
            // Clock skew between the web server and the database.
            'a moment ahead' => ['2026-09-25 12:00:20', 'Just now'],
            'one minute' => ['2026-09-25 11:59:00', '1 minute ago'],
            'minutes' => ['2026-09-25 11:15:00', '45 minutes ago'],
            'one hour' => ['2026-09-25 11:00:00', '1 hour ago'],
            'hours' => ['2026-09-24 13:00:00', '23 hours ago'],
            'one day' => ['2026-09-24 12:00:00', '1 day ago'],
            'days' => ['2026-08-27 12:00:00', '29 days ago'],
            'a month back is a date' => ['2026-08-26 12:00:00', '26 Aug 2026'],
        ];
    }

    /**
     * @dataProvider moments
     */
    public function testAMomentIsDescribedByHowLongAgoItWas(string $moment, string $expected): void
    {
        self::assertSame($expected, $this->relative->describe(new DateTimeImmutable($moment)));
    }
}
