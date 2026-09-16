<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
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
        $palette = ['Entertainment' => '#8b5cf6', 'Utilities' => '#0ea5e9', 'Software' => '#10b981'];

        foreach ($palette as $name => $colour) {
            $categories[$name] = $this->categories->create($scope, $name, $colour);
        }

        $today = $this->clock->today();
        $count = 0;

        foreach ($this->fixtures() as $fixture) {
            $this->subscriptions->create($scope, [
                'name' => $fixture['name'],
                'price' => $fixture['price'],
                'currency' => 'GBP',
                'subscription_type' => SubscriptionType::Recurring->value,
                'billing_cycle' => $fixture['cycle'],
                'next_payment_date' => $today->modify($fixture['due'])->format('Y-m-d'),
                'start_date' => $today->modify('-1 year')->format('Y-m-d'),
                'category_id' => (string) $categories[$fixture['category']],
                'is_active' => '1',
                'is_trial' => $fixture['trial'] ? '1' : '0',
                'trial_end_date' => $fixture['trial'] ? $today->modify('+9 days')->format('Y-m-d') : '',
                'converts_to_price' => $fixture['trial'] ? '14.99' : '',
                'notes' => '',
                'tags' => $fixture['tags'],
                'website_url' => '',
            ]);

            $count++;
        }

        return ['created' => true, 'email' => self::EMAIL, 'password' => $password, 'subscriptions' => $count];
    }

    /**
     * Invented names, on purpose. A demonstration that lists real services
     * reads as an endorsement, and the numbers are what it is showing.
     *
     * @return list<array{name: string, price: string, cycle: string, due: string,
     *     category: string, trial: bool, tags: string}>
     */
    private function fixtures(): array
    {
        return [
            ['name' => 'Streamly', 'price' => '12.99', 'cycle' => BillingCycle::Monthly->value,
                'due' => '+4 days', 'category' => 'Entertainment', 'trial' => false, 'tags' => 'shared, family'],
            ['name' => 'Podcatcher Plus', 'price' => '4.50', 'cycle' => BillingCycle::Monthly->value,
                'due' => '+11 days', 'category' => 'Entertainment', 'trial' => false, 'tags' => ''],
            ['name' => 'Fibre broadband', 'price' => '32.00', 'cycle' => BillingCycle::Monthly->value,
                'due' => '+18 days', 'category' => 'Utilities', 'trial' => false, 'tags' => 'household'],
            ['name' => 'Cloud backup', 'price' => '59.00', 'cycle' => BillingCycle::Yearly->value,
                'due' => '+2 months', 'category' => 'Software', 'trial' => false, 'tags' => ''],
            ['name' => 'Design suite', 'price' => '21.99', 'cycle' => BillingCycle::Monthly->value,
                'due' => '+25 days', 'category' => 'Software', 'trial' => false, 'tags' => 'work'],
            ['name' => 'Recipe box', 'price' => '0.00', 'cycle' => BillingCycle::Monthly->value,
                'due' => '+9 days', 'category' => 'Entertainment', 'trial' => true, 'tags' => 'trial'],
        ];
    }
}
