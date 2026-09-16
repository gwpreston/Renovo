<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * The fields an import can fill in.
 *
 * A closed list rather than "whatever the file has", because a mapping screen
 * that offered every column of the subscriptions table would invite somebody to
 * map a household id. What is absent is as deliberate as what is present: no
 * owner, no household, no id. Imported rows belong to the importing user's
 * household and are owned according to the scope, exactly like rows created in
 * the form.
 */
enum ImportField: string
{
    case Name = 'name';
    case Price = 'price';
    // Its own field rather than a second spelling of Price, because the unit is
    // not something to guess at. A column of "1099" is either £10.99 or
    // £1,099.00 and nothing about the number says which — this application's own
    // export writes minor units, most spreadsheets write decimals, and reading
    // one as the other is off by a hundred rather than visibly wrong.
    case PriceMinor = 'price_minor';
    case Currency = 'currency';
    case SubscriptionType = 'subscription_type';
    case BillingCycle = 'billing_cycle';
    case CycleDays = 'cycle_days';
    case NextPaymentDate = 'next_payment_date';
    case StartDate = 'start_date';
    case Category = 'category';
    case Tags = 'tags';
    case Notes = 'notes';
    case IsActive = 'is_active';
    case NoticePeriodAmount = 'notice_period_amount';
    case NoticePeriodUnit = 'notice_period_unit';
    case TrialEndDate = 'trial_end_date';
    case ConvertsToPrice = 'converts_to_price';
    case ConvertsToPriceMinor = 'converts_to_price_minor';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'Name',
            self::Price => 'Price',
            self::PriceMinor => 'Price in minor units',
            self::Currency => 'Currency',
            self::SubscriptionType => 'Type',
            self::BillingCycle => 'Billing cycle',
            self::CycleDays => 'Days between payments',
            self::NextPaymentDate => 'Next payment date',
            self::StartDate => 'Start date',
            self::Category => 'Category',
            self::Tags => 'Tags',
            self::Notes => 'Notes',
            self::IsActive => 'Active',
            self::NoticePeriodAmount => 'Notice period',
            self::NoticePeriodUnit => 'Notice period unit',
            self::TrialEndDate => 'Trial end date',
            self::ConvertsToPrice => 'Price after trial',
            self::ConvertsToPriceMinor => 'Price after trial, in minor units',
        };
    }

    /**
     * Without these two an imported row is not a subscription. Everything else
     * has a defensible default.
     */
    public function isRequired(): bool
    {
        return $this === self::Name || $this === self::Price;
    }

    public function hint(): string
    {
        return match ($this) {
            self::Price => 'A decimal amount, for example 9.99',
            self::PriceMinor, self::ConvertsToPriceMinor =>
                'A whole number of pence or cents, for example 999. Takes precedence over the decimal price.',
            self::BillingCycle => 'weekly, monthly, quarterly, yearly or a number of days',
            self::NextPaymentDate, self::StartDate, self::TrialEndDate => 'YYYY-MM-DD or DD/MM/YYYY',
            self::Tags => 'Separated by commas or semicolons',
            self::IsActive => 'yes/no, true/false or 1/0',
            self::NoticePeriodUnit => 'days, weeks or months',
            default => '',
        };
    }
}
