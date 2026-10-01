<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Domain\AlertType;
use App\Domain\Entity\NotificationPreferences;
use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\PriceChangeSource;
use App\I18n\Translator;
use App\Notification\Alert;
use App\Security\Scope;
use App\Service\PriceHistoryService;
use App\Service\SubscriptionService;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * The price changes one member should hear about.
 *
 * A change is a price-history row, and the row is the occurrence: its id is the
 * ledger key, so a re-run is silent and a scheduled rise is announced once, when
 * it is scheduled, rather than again when it takes effect (taking effect writes
 * no new row). Only a genuine change counts:
 *
 *  - a **manual** edit or a newly **scheduled** price — yes;
 *  - a subscription's **first** price — no, nothing changed;
 *  - a **trial conversion** — no, that is the trial alert's news, and saying it
 *    twice in two tones of voice is how people learn to ignore both;
 *  - a **currency** change — no, the amount moved and the price did not;
 *  - an **intro price** itself — no: the offer starting is the member's own
 *    news, and what they need to hear about is its end, which is the next
 *    row and is announced with the offer's wording.
 *
 * The ledger key is the row id, and moving an offer's end moves that row
 * rather than writing a new one, so a revised end is not announced twice.
 *
 * Who hears is decided by the scope, not by ownership: anybody who can see the
 * subscription and has not turned the alert off. The scoping layer is what
 * keeps another member's "only me" subscription out of it, as it does
 * everywhere else.
 */
final class PriceChangeScanner
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryService $priceHistory,
        private readonly MoneyFormatter $money,
        private readonly Translator $translator,
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * Every genuine change recorded after `$since`, oldest first.
     *
     * @return list<Alert>
     */
    public function alerts(Scope $scope, NotificationPreferences $preferences, DateTimeImmutable $since): array
    {
        if (!$preferences->priceChangeAlerts) {
            return [];
        }

        $visible = [];
        foreach ($this->subscriptions->allForStats($scope) as $subscription) {
            $visible[$subscription->id] = $subscription;
        }

        $alerts = [];
        foreach ($this->priceHistory->historyBySubscription($scope) as $subscriptionId => $changes) {
            $subscription = $visible[$subscriptionId] ?? null;
            if ($subscription === null) {
                // Paused, cancelled or not this member's to see.
                continue;
            }

            foreach ($changes as $index => $change) {
                $previous = $changes[$index - 1] ?? null;
                if ($previous === null || !$this->isGenuine($change, $previous) || $change->createdAt <= $since) {
                    continue;
                }

                $alerts[] = $this->alert($subscription, $previous, $change);
            }
        }

        usort($alerts, static fn (Alert $a, Alert $b): int => (int) $a->occurrenceKey <=> (int) $b->occurrenceKey);

        return $alerts;
    }

    /**
     * Whether a row records the price moving, as opposed to a price arriving,
     * a trial ending or an amount being re-denominated.
     */
    private function isGenuine(PriceChange $change, PriceChange $previous): bool
    {
        if (!in_array($change->source, [PriceChangeSource::Manual, PriceChangeSource::Scheduled], true)) {
            return false;
        }

        if ($change->isPromotional) {
            return false;
        }

        return $change->price->currency === $previous->price->currency
            && $change->price->amountMinor !== $previous->price->amountMinor;
    }

    private function alert(Subscription $subscription, PriceChange $previous, PriceChange $change): Alert
    {
        $difference = $change->price->amountMinor - $previous->price->amountMinor;
        $endsOffer = $change->endsOfferFrom($previous);

        // The same alert, routes and ledger; only the words change when the
        // price being replaced was an intro offer.
        $lines = [
            $endsOffer
                ? $this->translator->trans('alert.price_change.offer_line', [
                    'new' => $this->money->format($change->price),
                    'difference' => $this->money->formatMinor($difference, $change->price->currency, signed: true),
                    'date' => $change->effectiveFrom->format('j M Y'),
                ])
                : $this->translator->trans('alert.price_change.line', [
                    'old' => $this->money->format($previous->price),
                    'new' => $this->money->format($change->price),
                    'date' => $change->effectiveFrom->format('j M Y'),
                ]),
        ];

        if ($subscription->type->countsTowardsRecurringTotals() && $subscription->billingCycle !== null) {
            $yearly = $subscription->billingCycle->annualMinor(
                $difference,
                $subscription->cycleDays,
                $subscription->cycleInterval,
            );
            $lines[] = $this->translator->trans('alert.price_change.yearly', [
                'amount' => $this->money->formatMinor($yearly, $change->price->currency, signed: true),
            ]);
        }

        return new Alert(
            AlertType::PriceChange,
            Alert::SUBJECT_SUBSCRIPTION,
            $subscription->id,
            // The history row is the occurrence. One row, one alert, however
            // many runs see it.
            (string) $change->id,
            $this->translator->trans(
                match (true) {
                    $endsOffer => 'alert.price_change.title_offer_ends',
                    $difference > 0 => 'alert.price_change.title_rise',
                    default => 'alert.price_change.title_fall',
                },
                ['name' => $subscription->name],
            ),
            $lines,
            $this->appUrl === '' ? null : rtrim($this->appUrl, '/') . '/subscriptions/' . $subscription->id . '/money',
            $change->effectiveFrom,
            5,
        );
    }
}
