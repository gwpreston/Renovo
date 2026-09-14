<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Why a price-history row exists.
 *
 * Recorded because a step in a price chart is much more useful when it can say
 * what caused it — a free trial ending looks identical to a provider raising
 * their prices unless the row remembers which it was.
 */
enum PriceChangeSource: string
{
    /** The price the subscription was first created with. */
    case Initial = 'initial';

    /** Somebody edited the price. */
    case Manual = 'manual';

    /** A future-dated change that has now taken effect. */
    case Scheduled = 'scheduled';

    /** A free trial converting to its paid price. */
    case TrialConversion = 'trial_conversion';

    /** A bulk currency change, which re-denominates the amount. */
    case CurrencyChange = 'currency_change';

    public function labelKey(): string
    {
        return 'price_change.' . $this->value;
    }
}
