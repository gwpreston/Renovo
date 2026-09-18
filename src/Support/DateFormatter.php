<?php

declare(strict_types=1);

namespace App\Support;

use App\I18n\LocaleContext;
use DateTimeInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;

/**
 * Display formatting for dates, the counterpart to MoneyFormatter.
 *
 * ICU rather than PHP's own `date()`, because PHP formats month and day names
 * in English whatever language the page is in — the one part of a translated
 * page that stays stubbornly English.
 *
 * What a caller passes is a *skeleton*, not a pattern: `d MMM y` says "a day, an
 * abbreviated month and a year", and ICU decides what that looks like where the
 * reader is. `4 Sept 2026` in English, `4 sept. 2026` in French, `2026年9月4日`
 * in Japanese, `2026. szept. 4.` in Hungarian.
 *
 * The distinction is the whole point and it is easy to lose. Handing the
 * skeleton straight to `setPattern()` also produces translated month names, and
 * looks right in every language that happens to order its dates the way English
 * does — a Japanese reader would be given "4 9月 2026", which is the English
 * shape wearing Japanese words. IntlDatePatternGenerator is what turns the
 * request into that locale's own arrangement, separators and all.
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

    /** @var array<string, IntlDatePatternGenerator> */
    private array $generators = [];

    public function __construct(private readonly LocaleContext $locale)
    {
    }

    /**
     * @param string $skeleton The fields wanted, in ICU's notation — the order
     *                         and the separators are the locale's to choose.
     */
    public function format(DateTimeInterface $date, string $skeleton = 'd MMM y'): string
    {
        $formatted = $this->formatter($this->locale->get(), $skeleton)->format($date);

        // ICU can refuse a pattern it does not understand; the ISO date is a
        // worse answer than the formatted one and a better one than nothing.
        return $formatted === false ? $date->format('Y-m-d') : $formatted;
    }

    private function formatter(string $locale, string $skeleton): IntlDateFormatter
    {
        $key = $locale . '|' . $skeleton;

        if (!isset($this->formatters[$key])) {
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE);
            $formatter->setPattern($this->patternFor($locale, $skeleton));
            $this->formatters[$key] = $formatter;
        }

        return $this->formatters[$key];
    }

    /**
     * The locale's own pattern for the fields asked for.
     *
     * Falls back to the skeleton itself if ICU cannot make one of it, which
     * leaves the previous behaviour — translated names in the caller's order —
     * rather than no date at all.
     */
    private function patternFor(string $locale, string $skeleton): string
    {
        $generator = $this->generators[$locale] ??= new IntlDatePatternGenerator($locale);
        $pattern = $generator->getBestPattern($skeleton);

        return $pattern === false || $pattern === '' ? $skeleton : $pattern;
    }
}
