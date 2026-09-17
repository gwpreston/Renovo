<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a spend insight noticed.
 *
 * Each case is one rule over data the application already holds, and the rules
 * are deliberately small: an insight this application shows can be checked by
 * the member against their own rows, which rules out anything it cannot name
 * the arithmetic for.
 *
 * The order of the cases is the order they are shown in, and `rank()` is what
 * enforces it. It is urgency, not size: a trial converting on Friday can only
 * be acted on before Friday, a scheduled rise only before it lands, and an
 * overlap or a rarely-used subscription is as actionable next month as it is
 * today. Ranking on the figure instead would push a £4 trial below a £300
 * overlap and let it convert while the member read about the overlap.
 */
enum InsightKind: string
{
    /** A free trial about to start costing money. */
    case TrialConverting = 'trial_converting';

    /** An announced increase that has not taken effect yet. */
    case PriceRising = 'price_rising';

    /** An increase that already has. */
    case PriceRisen = 'price_risen';

    /** More than one active subscription in the same category. */
    case Overlap = 'overlap';

    /** Paid for, barely opened — and only when the usage signal is there. */
    case RarelyUsed = 'rarely_used';

    /**
     * Where this kind sorts against the others. Lower is shown first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::TrialConverting => 0,
            self::PriceRising => 1,
            self::PriceRisen => 2,
            self::Overlap => 3,
            self::RarelyUsed => 4,
        };
    }

    public function labelKey(): string
    {
        return 'insight.' . $this->value . '.headline';
    }

    public function detailKey(): string
    {
        return 'insight.' . $this->value . '.detail';
    }
}
