<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Money;
use App\I18n\LocaleContext;
use App\Support\DateFormatter;
use App\Support\MoneyFormatter;
use App\Support\NumberFormat;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Dates, money and numbers, seen from outside English.
 *
 * The application has one catalogue today, so `i18n:check` has nothing to
 * compare and a clean run proves very little. That is not the same as saying
 * the translation layer is untested: the half that no catalogue can fix is
 * *formatting*, and it is testable now, without shipping a translation.
 *
 * The failure this guards against is a template — or a service — writing a
 * figure in the only shape its author could see. "4 Sep 2026", "£12.99" and
 * "50%" are not values; they are one locale's rendering of three values, and
 * the moment somebody types one into a template it stops following the reader.
 * Setting the locale to French and looking at the same three figures is what
 * makes that visible: `4 sept. 2026`, `12,99 £` and `50 %` are the same data
 * in a different hand.
 *
 * French, specifically, because it differs from English in every way that
 * matters at once — month abbreviation, the currency sign after the amount
 * rather than before it, a comma for the decimal mark, and a space before the
 * percent sign that an English reader would never think to allow for.
 */
final class LocalisedFormattingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('The intl extension is what does the formatting.');
        }
    }

    public function testADateIsWrittenTheWayTheReaderWritesDates(): void
    {
        $date = new DateTimeImmutable('2026-09-04');

        // English abbreviates the month; which abbreviation is CLDR's to
        // choose and has changed between ICU releases ("Sep", then "Sept"), so
        // what is pinned here is the shape — day, month name, year — and not
        // the three or four letters in the middle.
        self::assertMatchesRegularExpression(
            '/^4 Sept? 2026$/',
            $this->dates('en_GB')->format($date, 'd MMM y'),
        );
        self::assertSame('4 sept. 2026', $this->dates('fr_FR')->format($date, 'd MMM y'));

        // The order is the locale's too, not only the words. This is the
        // assertion that fails if the skeleton is ever handed straight to
        // setPattern(): a Japanese reader would then be given "4 9月 2026",
        // which is the English arrangement wearing Japanese words.
        self::assertSame('2026年9月4日', $this->dates('ja_JP')->format($date, 'd MMM y'));

        // And the separators with it: Hungarian writes the year first and puts
        // a full stop after every field.
        self::assertSame('2026. szept. 4.', $this->dates('hu_HU')->format($date, 'd MMM y'));
    }

    public function testAMonthHeadingIsWrittenInTheReadersLanguage(): void
    {
        $september = new DateTimeImmutable('2026-09-01');

        self::assertSame('September 2026', $this->dates('en_GB')->format($september, 'MMMM y'));
        self::assertSame('septembre 2026', $this->dates('fr_FR')->format($september, 'MMMM y'));
        self::assertSame('2026年9月', $this->dates('ja_JP')->format($september, 'MMMM y'));
    }

    public function testMoneyCarriesItsSignWhereTheReaderExpectsIt(): void
    {
        self::assertSame('£12.99', $this->money('en_GB')->format(Money::of(1299, 'GBP')));
        self::assertSame('12,99 €', $this->normalise($this->money('fr_FR')->format(Money::of(1299, 'EUR'))));

        // Which sign stands for a currency is the reader's convention as much
        // as the currency's: a French reader is shown "£GB" for sterling,
        // because to them a bare "£" is not unambiguous. Nothing in the
        // application decides that, and nothing should try to.
        self::assertStringStartsWith('12,99', $this->normalise($this->money('fr_FR')->format(Money::of(1299, 'GBP'))));
    }

    /**
     * The currency decides the number of decimal places; the locale decides
     * how they are written. Both have to be right at once, which is why the
     * exponent is not a property of the formatter.
     */
    public function testMoneyKeepsItsCurrencysDecimalPlacesInEveryLocale(): void
    {
        // Yen has no minor unit at all: 1299 minor units are 1299 yen, and
        // neither reader is shown a decimal point the currency does not have.
        self::assertStringContainsString('1,299', $this->money('en_GB')->format(Money::of(1299, 'JPY')));
        self::assertStringNotContainsString('.00', $this->money('en_GB')->format(Money::of(1299, 'JPY')));

        $french = $this->normalise($this->money('fr_FR')->format(Money::of(1299, 'JPY')));

        self::assertStringContainsString('1 299', $french);
        self::assertStringNotContainsString(',00', $french);

        // Sterling has two, in both hands.
        self::assertSame('£12.99', $this->money('en_GB')->format(Money::of(1299, 'GBP')));
        self::assertSame('12,99 €', $this->normalise($this->money('fr_FR')->format(Money::of(1299, 'EUR'))));
    }

    public function testAChangeIsSignedWithTheLocalesOwnPlusSign(): void
    {
        self::assertSame('+£2.00', $this->money('en_GB')->format(Money::of(200, 'GBP'), true));

        // A fall needs no help: the formatter has already written the minus.
        self::assertSame('-£2.00', $this->money('en_GB')->format(Money::of(-200, 'GBP'), true));
        self::assertSame('£0.00', $this->money('en_GB')->format(Money::of(0, 'GBP'), true));
    }

    /**
     * The sign alone, for a label beside the code. A locale that shares "$"
     * between currencies qualifies it, and a currency with no sign at all
     * falls back to its code rather than ICU's "¤".
     */
    public function testACurrencysSignIsTheOneTheReaderWrites(): void
    {
        self::assertSame('£', $this->money('en_GB')->symbol('GBP'));
        self::assertSame('€', $this->money('en_GB')->symbol('EUR'));
        self::assertSame('US$', $this->money('en_GB')->symbol('USD'));
        self::assertSame('£', $this->money('en_GB')->symbol('GBP'), 'The cached formatter changed its currency.');
        self::assertSame('XXX', $this->money('en_GB')->symbol('XXX'));
    }

    public function testAPercentageIsWrittenTheWayTheReaderWritesPercentages(): void
    {
        self::assertSame('50%', $this->numbers('en_GB')->percent(50));

        // French puts a narrow no-break space between the number and the sign.
        self::assertSame('50 %', $this->normalise($this->numbers('fr_FR')->percent(50)));

        self::assertSame('+4%', $this->numbers('en_GB')->percent(4, true));
        self::assertSame('-4%', $this->numbers('en_GB')->percent(-4, true));
        self::assertSame('0%', $this->numbers('en_GB')->percent(0, true));
    }

    public function testAnExamplePlaceholderIsWrittenInTheReadersOwnShape(): void
    {
        self::assertSame('9.99', $this->numbers('en_GB')->decimal(9.99, 2));
        self::assertSame('9,99', $this->numbers('fr_FR')->decimal(9.99, 2));

        // And the grouping separator with it, which is the other half of the
        // same problem: 1,500 and 1 500 are the same budget.
        self::assertSame('1,500', $this->numbers('en_GB')->decimal(1500));
        self::assertSame('1 500', $this->normalise($this->numbers('fr_FR')->decimal(1500)));
    }

    public function testThePercentSignOnItsOwnIsTheLocalesOwn(): void
    {
        self::assertSame('%', $this->numbers('en_GB')->percentSymbol());
        self::assertSame('%', $this->numbers('fr_FR')->percentSymbol());
    }

    /**
     * A number the reader typed comes back whichever mark they used for the
     * decimal, so a French placeholder is not a trap: `9,99` typed into the
     * field it is a hint for is nine ninety-nine.
     */
    public function testAnAmountTypedInTheReadersOwnShapeIsUnderstood(): void
    {
        self::assertSame(999, Money::fromUserInput('9,99', 'GBP')->amountMinor);
        self::assertSame(999, Money::fromUserInput('9.99', 'GBP')->amountMinor);
        self::assertSame(150000, Money::fromUserInput('1 500,00', 'GBP')->amountMinor);
    }

    private function dates(string $locale): DateFormatter
    {
        return new DateFormatter(new LocaleContext($locale));
    }

    private function money(string $locale): MoneyFormatter
    {
        return new MoneyFormatter(new LocaleContext($locale));
    }

    private function numbers(string $locale): NumberFormat
    {
        return new NumberFormat(new LocaleContext($locale));
    }

    /**
     * ICU separates with the spaces typography asks for — a narrow no-break
     * space in French — and which one a given ICU version picks has changed
     * more than once. The test is about where the sign goes and what mark the
     * decimal takes, not about which flavour of space is between them.
     */
    private function normalise(string $formatted): string
    {
        return str_replace(["\u{202f}", "\u{00a0}", "\u{2009}"], ' ', $formatted);
    }
}
