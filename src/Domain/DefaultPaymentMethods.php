<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The payment methods a new household starts with.
 *
 * Names are catalogue keys, resolved once when the household is created, so a
 * household on a German instance starts with German names — after which they
 * are ordinary rows, renamed or removed like any other. Nothing re-reads this
 * list for an existing household.
 *
 * Each carries a generic icon from the application's own set, never a brand's
 * mark. PayPal is a wallet and the App Store is a phone: the logos belong to
 * their owners, and a household that wants one uploads it.
 */
final class DefaultPaymentMethods
{
    /**
     * The glyphs a payment method may carry, by the name `assets/js/icons.js`
     * knows them by. A stored icon is checked against this list, so a row can
     * never name a drawing the page does not have.
     */
    public const ICONS = [
        'payment-card',
        'payment-bank',
        'payment-transfer',
        'payment-repeat',
        'payment-wallet',
        'payment-cash',
        'payment-gift',
        'payment-phone',
    ];

    /** What a method with neither a logo nor an icon of its own is drawn as. */
    public const FALLBACK_ICON = 'payment-card';

    /**
     * @return list<array{key: string, icon: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'payment_methods.default.credit_card', 'icon' => 'payment-card'],
            ['key' => 'payment_methods.default.debit_card', 'icon' => 'payment-card'],
            ['key' => 'payment_methods.default.direct_debit', 'icon' => 'payment-bank'],
            ['key' => 'payment_methods.default.bank_transfer', 'icon' => 'payment-transfer'],
            ['key' => 'payment_methods.default.standing_order', 'icon' => 'payment-repeat'],
            ['key' => 'payment_methods.default.paypal', 'icon' => 'payment-wallet'],
            ['key' => 'payment_methods.default.cash', 'icon' => 'payment-cash'],
            ['key' => 'payment_methods.default.gift_card', 'icon' => 'payment-gift'],
            ['key' => 'payment_methods.default.app_store', 'icon' => 'payment-phone'],
            ['key' => 'payment_methods.default.google_play', 'icon' => 'payment-phone'],
        ];
    }
}
