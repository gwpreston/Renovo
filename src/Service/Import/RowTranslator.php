<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\BillingCycle;
use App\Domain\Currency;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\SubscriptionType;
use DateTimeImmutable;

/**
 * One mapped row, as SubscriptionService wants it.
 *
 * Everything here is interpretation of other people's file formats, which is
 * why it is a class of its own rather than conditions sprinkled through the
 * importer: the guesses are all in one place, they are all stated, and they are
 * all testable.
 *
 * Two of them are worth calling out.
 *
 * **Dates.** `2026-03-01` is unambiguous and tried first. `01/03/2026` is not:
 * it is March in most of the world and January in the United States. This
 * application defaults to en_GB, its users are self-hosting it, and the
 * day-first reading is taken. A file using the other convention will import
 * dates that are wrong in a visible way — the preview shows every parsed date
 * before anything is written, which is the point of having a preview.
 *
 * **Prices.** Parsed by `Money::fromUserInput` further down the stack, which
 * already knows that "1,234.56" and "1.234,56" are the same number. Nothing
 * here touches a float.
 */
final class RowTranslator
{
    /**
     * Words other trackers use for billing cycles.
     *
     * @var array<string, string>
     */
    private const CYCLE_WORDS = [
        'weekly' => 'weekly',
        'week' => 'weekly',
        '1 week' => 'weekly',
        'every week' => 'weekly',
        'fortnightly' => 'custom_days',
        'monthly' => 'monthly',
        'month' => 'monthly',
        '1 month' => 'monthly',
        'every month' => 'monthly',
        'per month' => 'monthly',
        'quarterly' => 'quarterly',
        'quarter' => 'quarterly',
        '3 months' => 'quarterly',
        'yearly' => 'yearly',
        'annual' => 'yearly',
        'annually' => 'yearly',
        'year' => 'yearly',
        '1 year' => 'yearly',
        'per year' => 'yearly',
        '12 months' => 'yearly',
    ];

    /**
     * @param array<string, string> $mapping Field name => source header.
     */
    public function __construct(
        private readonly array $mapping,
        private readonly string $defaultCurrency,
    ) {
    }

    /**
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    public function translate(array $row): array
    {
        $currency = Currency::normalise($this->value($row, ImportField::Currency));
        if (!Currency::isValidCode($currency)) {
            $currency = $this->defaultCurrency;
        }

        [$cycle, $cycleDays] = $this->cycle(
            $this->value($row, ImportField::BillingCycle),
            $this->value($row, ImportField::CycleDays),
        );

        $trialEnd = $this->date($this->value($row, ImportField::TrialEndDate));

        $input = [
            'name' => $this->value($row, ImportField::Name),
            'price' => $this->price($row, ImportField::Price, ImportField::PriceMinor, $currency),
            'currency' => $currency,
            'subscription_type' => $this->type($this->value($row, ImportField::SubscriptionType))->value,
            'billing_cycle' => $cycle,
            'cycle_days' => $cycleDays,
            'next_payment_date' => $this->date($this->value($row, ImportField::NextPaymentDate)),
            'start_date' => $this->date($this->value($row, ImportField::StartDate)),
            'notes' => $this->value($row, ImportField::Notes),
            'tags' => $this->tags($this->value($row, ImportField::Tags)),
            'notice_period_amount' => $this->digits($this->value($row, ImportField::NoticePeriodAmount)),
            'notice_period_unit' => $this->noticeUnit($this->value($row, ImportField::NoticePeriodUnit)),
            'is_active' => $this->boolean($this->value($row, ImportField::IsActive), true) ? '1' : '0',
            'is_trial' => $trialEnd !== '' ? '1' : '0',
            'trial_end_date' => $trialEnd,
            'converts_to_price' => $this->price(
                $row,
                ImportField::ConvertsToPrice,
                ImportField::ConvertsToPriceMinor,
                $currency,
            ),
        ];

        // Category is resolved to an id by the importer, which can create one;
        // this class only reports the name it found.
        return $input;
    }

    /**
     * A price, from whichever of the two columns the mapping named.
     *
     * The minor-unit column wins when both are mapped: it is the exact figure
     * and the decimal one is a rendering of it. Converting back to a decimal
     * string here rather than to an integer keeps everything downstream going
     * through `Money::fromUserInput`, which is the one parser that knows what
     * "1.234,56" means and how many places a currency has.
     *
     * @param array<string, string> $row
     */
    private function price(array $row, ImportField $decimal, ImportField $minor, string $currency): string
    {
        $raw = $this->value($row, $minor);

        if ($raw !== '' && preg_match('/^-?\d+$/', $raw) === 1 && Currency::isValidCode($currency)) {
            return Money::of((int) $raw, $currency)->toDecimalString();
        }

        return $this->value($row, $decimal);
    }

    /**
     * @param array<string, string> $row
     */
    public function categoryName(array $row): string
    {
        return $this->value($row, ImportField::Category);
    }

    /**
     * @param array<string, string> $row
     */
    private function value(array $row, ImportField $field): string
    {
        $header = $this->mapping[$field->value] ?? null;
        if ($header === null) {
            return '';
        }

        return trim($row[$header] ?? '');
    }

    /**
     * @return array{0: string, 1: string} The cycle value and the custom-day count.
     */
    private function cycle(string $raw, string $days): array
    {
        $normalised = strtolower(trim($raw));

        if ($normalised === 'fortnightly' || $normalised === 'biweekly') {
            return ['custom_days', '14'];
        }

        if (isset(self::CYCLE_WORDS[$normalised])) {
            return [self::CYCLE_WORDS[$normalised], $days];
        }

        $direct = BillingCycle::tryFromString($normalised);
        if ($direct !== null) {
            return [$direct->value, $days];
        }

        // "every 45 days", or just "45". The pattern only matches when there
        // are digits, so an empty value falls through to the default below.
        if (preg_match('/(\d+)\s*(day|days)?/', $normalised, $matches) === 1) {
            $count = (int) $matches[1];
            if ($count > 0 && $count <= 3650) {
                return ['custom_days', (string) $count];
            }
        }

        if ($days !== '' && ctype_digit($days)) {
            return ['custom_days', $days];
        }

        // Nothing recognisable. Monthly is the commonest cycle by a wide margin
        // and is shown in the preview, where it can be corrected before it is
        // written.
        return [BillingCycle::Monthly->value, ''];
    }

    private function type(string $raw): SubscriptionType
    {
        $normalised = str_replace([' ', '-'], '_', strtolower(trim($raw)));

        return match ($normalised) {
            'one_off', 'oneoff', 'once', 'single', 'one_time', 'onetime' => SubscriptionType::OneOff,
            'lifetime', 'perpetual', 'forever' => SubscriptionType::Lifetime,
            default => SubscriptionType::Recurring,
        };
    }

    /**
     * @return string A `Y-m-d` date, or empty when nothing parsed.
     */
    private function date(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // ISO first: it is unambiguous, and it is what this application emits.
        foreach (['!Y-m-d', '!Y/m/d', '!d/m/Y', '!d-m-Y', '!d.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        // A full timestamp, as produced by this application's own export.
        $parsed = date_create_immutable($raw);

        return $parsed === false ? '' : $parsed->format('Y-m-d');
    }

    /**
     * @return list<string>
     */
    private function tags(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,;|]/', $raw) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $tag): string => trim($tag), $parts),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    private function digits(string $raw): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';

        return $digits;
    }

    private function noticeUnit(string $raw): string
    {
        $normalised = rtrim(strtolower(trim($raw)), 's');

        return match ($normalised) {
            'week' => NoticePeriod::UNIT_WEEKS,
            'month' => NoticePeriod::UNIT_MONTHS,
            'day' => NoticePeriod::UNIT_DAYS,
            default => '',
        };
    }

    /**
     * A tri-state read of a column that might say "active" or might say
     * "inactive". An unrecognised value takes the default rather than being
     * treated as false — a missing column must not deactivate everything.
     */
    private function boolean(string $raw, bool $default): bool
    {
        $normalised = strtolower(trim($raw));

        if ($normalised === '') {
            return $default;
        }

        if (in_array($normalised, ['1', 'true', 'yes', 'y', 'active', 'enabled', 'on'], true)) {
            return true;
        }

        if (in_array($normalised, ['0', 'false', 'no', 'n', 'inactive', 'disabled', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
