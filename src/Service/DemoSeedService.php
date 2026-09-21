<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Domain\SubscriptionType;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\ScopeFactory;
use App\Support\Clock;

/**
 * Builds the household a read-only demonstration shows.
 *
 * Written through the ordinary services rather than as SQL, which is what
 * makes the demo an honest one: the subscriptions it shows went through the
 * same validation, the same price-history write and the same scoping as
 * anybody else's, so the figures on the dashboard are computed the way they
 * would be for a real household rather than typed in to look right.
 *
 * The account it creates is an ordinary member of its own household, and that
 * is the whole of the isolation story. It is not an instance administrator, it
 * belongs to no other household, and the scoping layer answers "what may this
 * account see" for it exactly as for anybody — so a demo instance that also
 * hosts real accounts shows the demo visitor the demo data and nothing else.
 */
final class DemoSeedService
{
    public const EMAIL = 'demo@renovo.local';

    public function __construct(
        private readonly UserRepository $users,
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly CategoryService $categories,
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryService $priceHistory,
        private readonly ScopeFactory $scopes,
        private readonly PasswordHasher $hasher,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create the demo account and its data, or do nothing if it already
     * exists. Returns the password when an account was created, so that
     * whoever ran the command can sign in as it once.
     *
     * @return array{created: bool, email: string, password: string|null, subscriptions: int}
     */
    public function seed(): array
    {
        $existing = $this->users->findByEmail(self::EMAIL);
        if ($existing !== null) {
            return ['created' => false, 'email' => self::EMAIL, 'password' => null, 'subscriptions' => 0];
        }

        // Random, and printed once. A demo account with a published password
        // is still an account on somebody's server.
        $password = bin2hex(random_bytes(9));

        $userId = $this->users->create(
            self::EMAIL,
            'Demo',
            $this->hasher->hash($password),
            false,
            $this->clock->now(),
        );

        $householdId = $this->households->create('Demo household', $userId);
        $this->memberships->create($householdId, $userId, Role::OwnerAdmin);

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('The demo account could not be read back after creation.');
        }

        $scope = $this->scopes->forUser($user, $householdId);

        $categories = [];
        $palette = [
            'Entertainment' => '#8b5cf6',
            'Utilities' => '#0ea5e9',
            'Software' => '#10b981',
            'Home' => '#f59e0b',
            'Health' => '#ef4444',
        ];

        foreach ($palette as $name => $colour) {
            $categories[$name] = $this->categories->create($scope, $name, $colour);
        }

        $today = $this->clock->today();
        $count = 0;

        foreach ($this->fixtures() as $fixture) {
            // Each subscription's dates hang off its own next payment rather
            // than off today, which is what keeps a yearly bill landing in the
            // same month in every year it has run: a start date three years
            // before the next renewal is three renewals ago to the day, where
            // "today minus three years" would drift by however far into its
            // cycle the subscription happens to be.
            $due = $today->modify($fixture['due']);
            $start = $due->modify($fixture['start']);

            $id = $this->subscriptions->create($scope, [
                'name' => $fixture['name'],
                // The price it started at, not the price it is at now. Where a
                // fixture has since had a rise, that rise is recorded below
                // with the date it took effect, so the history holds the step
                // and the reconstruction of the past twelve months prices each
                // charge at whatever was being paid that month.
                'price' => $fixture['price'],
                'currency' => 'GBP',
                'subscription_type' => SubscriptionType::Recurring->value,
                'billing_cycle' => $fixture['cycle'],
                'next_payment_date' => $due->format('Y-m-d'),
                'start_date' => $start->format('Y-m-d'),
                'category_id' => (string) $categories[$fixture['category']],
                'is_active' => $fixture['active'] ? '1' : '0',
                'is_trial' => $fixture['trial'] === null ? '0' : '1',
                'trial_end_date' => $fixture['trial'] === null
                    ? ''
                    : $today->modify($fixture['trial']['ends'])->format('Y-m-d'),
                'converts_to_price' => $fixture['trial']['price'] ?? '',
                'notes' => '',
                'tags' => $fixture['tags'],
                'website_url' => '',
            ]);

            foreach ($fixture['rises'] as $rise) {
                // A rise that has already happened: the history row *and* the
                // current price, written together by the same call an edit
                // makes, so nothing about this household is in a state the
                // application could not have got into by itself.
                $this->priceHistory->recordCurrentPrice(
                    $scope,
                    $id,
                    Money::fromUserInput($rise['price'], 'GBP'),
                    PriceChangeSource::Manual,
                    $userId,
                    null,
                    $due->modify($rise['from']),
                );
            }

            if ($fixture['scheduled'] !== null) {
                // A rise that has been announced but not taken. It shows as a
                // step in the forecast on the month it lands and changes
                // nothing about what is being paid today, which is the whole
                // reason the forecast is a walk rather than a multiplication.
                $this->priceHistory->schedule($scope, $id, [
                    'price' => $fixture['scheduled']['price'],
                    'currency' => 'GBP',
                    'effective_from' => $due->modify($fixture['scheduled']['from'])->format('Y-m-d'),
                    'note' => 'Announced increase',
                ]);
            }

            $count++;
        }

        return ['created' => true, 'email' => self::EMAIL, 'password' => $password, 'subscriptions' => $count];
    }

    /**
     * Invented names, on purpose. A demonstration that lists real services
     * reads as an endorsement, and the numbers are what it is showing.
     *
     * **The set is chosen to make the charts say something.** Both twelve-month
     * charts — the year behind and the year ahead — are drawn from individual
     * charges falling in the month they actually fall, so a household of
     * fourteen identical monthly subscriptions that all began on the same day
     * would draw two flat lines and demonstrate nothing. What makes a shape:
     *
     * - **Yearly and quarterly bills**, anchored to renew in *different*
     *   months, so the line has peaks where they land rather than a level
     *   average of them.
     * - **Staggered start dates**, some years back and some a few months, so
     *   the earlier half of the history has fewer subscriptions in it than the
     *   later half and the line climbs the way a real one does.
     * - **Price rises with the dates they took effect**, which is the only
     *   thing that makes the reconstruction of the past a step rather than
     *   today's price stretched backwards.
     * - **An announced rise** that has not happened yet, which steps the
     *   forecast and not the history.
     * - **A cancelled subscription**, which is in the months it ran and absent
     *   from every month ahead.
     * - **Two trials**, so the forecast's second line has something to
     *   separate from the first and the band between them is visible.
     *
     * Everything is in GBP deliberately. A second currency would be more
     * realistic and would also mean that an instance with no exchange rate
     * cached yet draws no charts at all — the demo would then be demonstrating
     * the refusal rather than the feature.
     *
     * `due` is relative to today; `start`, `rises` and `scheduled` are relative
     * to `due`, which is what keeps a yearly bill in the same month in every
     * year it has run.
     *
     * @return list<array{
     *     name: string,
     *     price: string,
     *     cycle: string,
     *     due: string,
     *     start: string,
     *     category: string,
     *     active: bool,
     *     trial: array{ends: string, price: string}|null,
     *     rises: list<array{price: string, from: string}>,
     *     scheduled: array{price: string, from: string}|null,
     *     tags: string
     * }>
     */
    private function fixtures(): array
    {
        return [
            // Running for three years and dearer than it was: the rise eight
            // months ago is a visible step part-way along the history.
            $this->fixture('Streamly', '9.99', BillingCycle::Monthly, '+4 days', '-3 years', 'Entertainment', [
                'rises' => [['price' => '12.99', 'from' => '-8 months']],
                'tags' => 'shared, family',
            ]),
            $this->fixture('Podcatcher Plus', '4.50', BillingCycle::Monthly, '+11 days', '-14 months', 'Entertainment'),
            // The household's largest monthly bill, and it went up five months
            // ago — the second step in the year behind.
            $this->fixture('Fibre broadband', '27.50', BillingCycle::Monthly, '+18 days', '-2 years', 'Utilities', [
                'rises' => [['price' => '32.00', 'from' => '-5 months']],
                'tags' => 'household',
            ]),
            $this->fixture('Cloud backup', '59.00', BillingCycle::Yearly, '+2 months', '-3 years', 'Software'),
            // Started seven months ago: the line steps up where it joined, in
            // the middle of the history rather than before it.
            $this->fixture('Design suite', '21.99', BillingCycle::Monthly, '+25 days', '-7 months', 'Software', [
                'tags' => 'work',
            ]),
            $this->fixture('Recipe box', '0.00', BillingCycle::Monthly, '+9 days', '-1 month', 'Entertainment', [
                'trial' => ['ends' => '+9 days', 'price' => '14.99'],
                'tags' => 'trial',
            ]),
            // The big one. A yearly insurance bill four years old is a single
            // tall spike in one month of each chart, which is the claim the
            // whole walk exists to make: it is a bill in its month, not a
            // twelfth of itself in every month.
            $this->fixture('Home insurance', '198.00', BillingCycle::Yearly, '+5 months', '-4 years', 'Home', [
                // Dated before this bill's last renewal rather than after it,
                // so the spike in the year behind is priced at what is
                // actually being paid now. A yearly bill cannot show a rise as
                // a step inside a twelve-month window — it only renews once in
                // one — so the choice here is which price its one charge wears,
                // and the answer that matches the subscription's own page is
                // the right one.
                'rises' => [['price' => '214.00', 'from' => '-14 months']],
                'tags' => 'household',
            ]),
            $this->fixture('Domain renewal', '15.00', BillingCycle::Yearly, '+7 months', '-5 years', 'Software'),
            // Quarterly: four smaller bumps a year rather than one spike or a
            // flat line, in months nothing else lands in.
            $this->fixture('Boiler cover', '45.00', BillingCycle::Quarterly, '+1 month', '-2 years', 'Home', [
                'tags' => 'household',
            ]),
            $this->fixture('Daily news', '6.99', BillingCycle::Monthly, '+14 days', '-4 months', 'Entertainment'),
            $this->fixture('Photo cloud', '89.00', BillingCycle::Yearly, '+10 months', '-2 years', 'Software'),
            // Cancelled, and deliberately expensive: it is in every month of
            // the year behind and in none of the year ahead, which is the
            // clearest thing the two charts can be put side by side to show.
            $this->fixture('Gym membership', '38.00', BillingCycle::Monthly, '+6 days', '-2 years', 'Health', [
                'active' => false,
            ]),
            // The rise that has not happened yet: nothing changes in the
            // history, and the forecast steps up three months out.
            $this->fixture('Coworking desk', '95.00', BillingCycle::Monthly, '+21 days', '-18 months', 'Software', [
                'scheduled' => ['price' => '110.00', 'from' => '+3 months'],
                'tags' => 'work',
            ]),
            // A second trial, converting later than the first, so the forecast
            // band widens in two steps rather than one.
            $this->fixture('Language tutor', '0.00', BillingCycle::Monthly, '+19 days', '-1 month', 'Health', [
                'trial' => ['ends' => '+19 days', 'price' => '9.99'],
                'tags' => 'trial',
            ]),
        ];
    }

    /**
     * One fixture, with the four fields most of them do not use defaulted.
     *
     * The table above is the readable part of this class and it is read down
     * its first three columns, so a row that has no price rise, no announced
     * one, no trial and has not been cancelled says none of those things.
     *
     * @param array{
     *     active?: bool,
     *     trial?: array{ends: string, price: string},
     *     rises?: list<array{price: string, from: string}>,
     *     scheduled?: array{price: string, from: string},
     *     tags?: string
     * } $extras
     * @return array{
     *     name: string,
     *     price: string,
     *     cycle: string,
     *     due: string,
     *     start: string,
     *     category: string,
     *     active: bool,
     *     trial: array{ends: string, price: string}|null,
     *     rises: list<array{price: string, from: string}>,
     *     scheduled: array{price: string, from: string}|null,
     *     tags: string
     * }
     */
    private function fixture(
        string $name,
        string $price,
        BillingCycle $cycle,
        string $due,
        string $start,
        string $category,
        array $extras = [],
    ): array {
        return [
            'name' => $name,
            'price' => $price,
            'cycle' => $cycle->value,
            'due' => $due,
            'start' => $start,
            'category' => $category,
            'active' => $extras['active'] ?? true,
            'trial' => $extras['trial'] ?? null,
            'rises' => $extras['rises'] ?? [],
            'scheduled' => $extras['scheduled'] ?? null,
            'tags' => $extras['tags'] ?? '',
        ];
    }
}
