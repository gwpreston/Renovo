<?php

declare(strict_types=1);

namespace App\Support;

use App\I18n\LocaleContext;
use DateTimeInterface;
use IntlDateFormatter;

/**
 * Display formatting for dates, the counterpart to MoneyFormatter.
 *
 * ICU rather than PHP's own `date()`, because PHP formats month and day names
 * in English whatever language the page is in — the one part of a translated
 * page that stays stubbornly English. The patterns here are ICU skeletons:
 * `d MMM y` is "4 Sep 2026" for an English reader and "4 sept. 2026" for a
 * French one, with the order decided by the locale rather than by the caller.
 *
 * It exists as a service rather than only as a Twig filter because a chart's
 * axis labels are strings built before the template runs. Those labels and the
 * dates beside them on the same page have to be formatted the same way, and the
 * only way to guarantee that is for both to come through here.
 *
 * The locale is read per call rather than fixed at construction, for the same
 * reason MoneyFormatter reads it per call: the scheduler formats each
 * recipient's dates in their own locale while building their digest.
 */
final class DateFormatter
{
    /** @var array<string, IntlDateFormatter> */
    private array $formatters = [];

    public function __construct(private readonly LocaleContext $locale)
    {
    }

    public function format(DateTimeInterface $date, string $pattern = 'd MMM y'): string
    {
        $formatted = $this->formatter($this->locale->get(), $pattern)->format($date);

        // ICU can refuse a pattern it does not understand; the ISO date is a
        // worse answer than the formatted one and a better one than nothing.
        return $formatted === false ? $date->format('Y-m-d') : $formatted;
    }

    private function formatter(string $locale, string $pattern): IntlDateFormatter
    {
        $key = $locale . '|' . $pattern;

        if (!isset($this->formatters[$key])) {
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE);
            $formatter->setPattern($pattern);
            $this->formatters[$key] = $formatter;
        }

        return $this->formatters[$key];
    }
}
