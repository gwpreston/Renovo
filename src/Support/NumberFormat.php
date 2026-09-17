<?php

declare(strict_types=1);

namespace App\Support;

use App\I18n\LocaleContext;
use NumberFormatter as IntlNumberFormatter;

/**
 * Plain numbers, in the reader's own locale.
 *
 * MoneyFormatter beside this one covers amounts, which are the numbers most of
 * the application shows. What was left over is the handful that are not money:
 * a percentage, the example value in a field's placeholder, the percent sign
 * on a threshold input.
 *
 * Each of those used to be written into a template as a literal — `100%`,
 * `placeholder="9.99"` — which is correct in English and wrong the moment the
 * page is read in a locale that groups with a space and marks the decimal with
 * a comma. The value is still the template's decision; the shape it is written
 * in is ICU's.
 *
 * The locale is read per call rather than fixed at construction, for the same
 * reason MoneyFormatter reads it per call: one process serves requests in
 * different languages, and the scheduler formats each recipient's figures in
 * their own.
 */
final class NumberFormat
{
    /** @var array<string, IntlNumberFormatter> */
    private array $formatters = [];

    public function __construct(private readonly LocaleContext $locale)
    {
    }

    /**
     * A decimal number: grouping and decimal mark from the locale.
     *
     * `9.99` in English, `9,99` in French. Used for the example amounts in
     * field placeholders, which are read as a hint about what to type — a
     * French reader typing `9,99` back into the field is understood, because
     * Money::fromUserInput accepts either mark.
     */
    public function decimal(int|float $value, int $fractionDigits = 0): string
    {
        $formatter = $this->formatter(IntlNumberFormatter::DECIMAL, $fractionDigits);
        $formatted = $formatter->format($value);

        return $formatted === false ? (string) $value : $formatted;
    }

    /**
     * A percentage, given as a whole percent: `12` prints as `12%`.
     *
     * ICU's percent format multiplies by a hundred, so the value handed to it
     * is divided first. Everything this application computes is already a
     * whole percent — a budget at 80% of its limit, a category at 12% of the
     * total — and asking every caller to divide would be one more place for a
     * factor of a hundred to go missing.
     *
     * `signed` prefixes a positive number with the locale's own plus sign,
     * which is what a year-over-year change needs: "+4%" says the direction
     * without relying on the colour it is printed in.
     */
    public function percent(int|float $value, bool $signed = false, int $fractionDigits = 0): string
    {
        $formatter = $this->formatter(IntlNumberFormatter::PERCENT, $fractionDigits);
        $formatted = $formatter->format($value / 100);

        if ($formatted === false) {
            $formatted = $value . $this->percentSymbol();
        }

        if ($signed && $value > 0) {
            return $formatter->getSymbol(IntlNumberFormatter::PLUS_SIGN_SYMBOL) . $formatted;
        }

        return $formatted;
    }

    /**
     * The percent sign on its own, for the suffix beside a field that takes a
     * threshold. The number is the reader's to type; the sign is ICU's.
     */
    public function percentSymbol(): string
    {
        $symbol = $this->formatter(IntlNumberFormatter::PERCENT, 0)
            ->getSymbol(IntlNumberFormatter::PERCENT_SYMBOL);

        return $symbol === false ? '%' : $symbol;
    }

    private function formatter(int $style, int $fractionDigits): IntlNumberFormatter
    {
        $key = $this->locale->get() . '|' . $style . '|' . $fractionDigits;

        return $this->formatters[$key] ??= $this->build($style, $fractionDigits);
    }

    private function build(int $style, int $fractionDigits): IntlNumberFormatter
    {
        $formatter = new IntlNumberFormatter($this->locale->get(), $style);
        $formatter->setAttribute(IntlNumberFormatter::FRACTION_DIGITS, $fractionDigits);

        return $formatter;
    }
}
