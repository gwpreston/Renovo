<?php

declare(strict_types=1);

namespace App\Support;

use App\I18n\Translator;
use DateTimeInterface;

/**
 * "3 hours ago", in the reader's language.
 *
 * PHP's intl has no relative-time formatter — `IntlDateFormatter`'s relative
 * styles stop at "yesterday" — so the phrases are ICU plural messages in the
 * catalogue and this chooses which one to use. A month or more back, the
 * phrase gives way to the date itself: "47 days ago" makes a reader do
 * arithmetic that a date would have done for them.
 *
 * Now is the injected clock's, so a test can pin it.
 */
final class RelativeTime
{
    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;
    private const DAYS_BEFORE_A_DATE = 30;

    public function __construct(
        private readonly Clock $clock,
        private readonly Translator $translator,
        private readonly DateFormatter $dates,
    ) {
    }

    public function describe(DateTimeInterface $moment): string
    {
        // A moment slightly in the future is the clock skew between the web
        // server and the database, not a visit from tomorrow.
        $seconds = max(0, $this->clock->now()->getTimestamp() - $moment->getTimestamp());

        return match (true) {
            $seconds < self::MINUTE => $this->translator->trans('relative.just_now'),
            $seconds < self::HOUR => $this->translator->trans(
                'relative.minutes_ago',
                ['count' => intdiv($seconds, self::MINUTE)],
            ),
            $seconds < self::DAY => $this->translator->trans(
                'relative.hours_ago',
                ['count' => intdiv($seconds, self::HOUR)],
            ),
            $seconds < self::DAYS_BEFORE_A_DATE * self::DAY => $this->translator->trans(
                'relative.days_ago',
                ['count' => intdiv($seconds, self::DAY)],
            ),
            default => $this->dates->format($moment, 'd MMM y'),
        };
    }
}
