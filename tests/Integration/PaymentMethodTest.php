<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\DefaultPaymentMethods;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Service\LogoStorage;
use App\Service\PaymentMethodService;
use App\Service\StatsService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Tests\Support\FakeUpload;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

use function DI\factory;

/**
 * Payment methods: the household's list, the assignment on a subscription, and
 * the grouping the breakdown is drawn from.
 *
 * The list follows categories' rules throughout — household-scoped, visible in
 * either isolation mode, unique by name ignoring case — so most of these are
 * the category guarantees asked again of the new table. The ones that are new
 * are the defaults, the logo, and `ON DELETE SET NULL`.
 */
final class PaymentMethodTest extends DatabaseTestCase
{
    private const LOGO_MAX_BYTES = 4096;

    private ContainerInterface $container;
    private PaymentMethodService $methods;
    private SubscriptionService $subscriptions;
    private string $logoDirectory;

    private Scope $owner;
    private Scope $editor;
    private Scope $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logoDirectory = sys_get_temp_dir() . '/renovo-payment-logos-' . bin2hex(random_bytes(6));
        $this->container = $this->buildContainer();
        $this->methods = $this->container->get(PaymentMethodService::class);
        $this->subscriptions = $this->container->get(SubscriptionService::class);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new \DateTimeImmutable();

        $ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $strangerId = $users->create('stranger@example.test', 'Stranger', 'hash', false, $now);

        $household = $households->create('Home', $ownerId);
        $memberships->create($household, $ownerId, Role::OwnerAdmin);
        $memberships->create($household, $editorId, Role::Editor);

        $other = $households->create('Next door', $strangerId);
        $memberships->create($other, $strangerId, Role::OwnerAdmin);

        $this->owner = Scope::forMember($ownerId, false, $household, Role::OwnerAdmin, IsolationMode::Shared);
        $this->editor = Scope::forMember($editorId, false, $household, Role::Editor, IsolationMode::Shared);
        $this->elsewhere = Scope::forMember($strangerId, false, $other, Role::OwnerAdmin, IsolationMode::Shared);
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();

        foreach ((array) glob($this->logoDirectory . '/*') as $path) {
            @unlink((string) $path);
        }
        @rmdir($this->logoDirectory);

        parent::tearDown();
    }

    public function testTheDefaultsAreSeededOnceAndNeverToppedUp(): void
    {
        $created = $this->methods->seedDefaults($this->owner, 'en');

        self::assertCount(10, $created);
        self::assertCount(10, $this->methods->all($this->owner));

        // A second call is a no-op — including after somebody removed one on
        // purpose, which a top-up would quietly put back.
        self::assertSame([], $this->methods->seedDefaults($this->owner, 'en'));

        $this->methods->delete($this->owner, $created['payment_methods.default.cash']);
        self::assertSame([], $this->methods->seedDefaults($this->owner, 'en'));
        self::assertCount(9, $this->methods->all($this->owner));

        // Another household's defaults are its own.
        self::assertSame([], $this->methods->all($this->elsewhere));
    }

    public function testTheDefaultsAreCatalogueNamesWithGenericIconsAndNoLogos(): void
    {
        $this->methods->seedDefaults($this->owner, 'en');

        $byName = [];
        foreach ($this->methods->all($this->owner) as $method) {
            $byName[$method->name] = $method;
        }

        self::assertEqualsCanonicalizing(
            [
                'Credit Card', 'Debit Card', 'Direct Debit', 'Bank Transfer', 'Standing Order',
                'PayPal', 'Cash', 'Gift Card', 'App Store', 'Google Play',
            ],
            array_keys($byName),
        );

        foreach ($byName as $method) {
            self::assertContains($method->icon, DefaultPaymentMethods::ICONS);
            self::assertNull($method->logoPath, 'no brand mark is bundled; a logo is something a household uploads');
            self::assertNull($method->colour);
        }

        // After seeding they are ordinary rows.
        $this->methods->update($this->owner, $byName['PayPal']->id, 'PayPal (joint)', '#1069bb');
        self::assertSame('PayPal (joint)', $this->methods->find($this->owner, $byName['PayPal']->id)?->name);
    }

    public function testTheListIsScopedToItsHousehold(): void
    {
        $id = $this->methods->create($this->owner, 'Joint card', null);

        self::assertNull($this->methods->find($this->elsewhere, $id));
        self::assertSame([], $this->methods->all($this->elsewhere));

        foreach (
            [
            fn () => $this->methods->update($this->elsewhere, $id, 'Mine now', null),
            fn () => $this->methods->clearLogo($this->elsewhere, $id),
            fn () => $this->methods->delete($this->elsewhere, $id),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('A household must not be able to change another household\'s payment method.');
            } catch (ScopeViolationException) {
                // Refused, as it should be.
            }
        }

        self::assertSame('Joint card', $this->methods->find($this->owner, $id)?->name);
    }

    public function testIsolatedModeDoesNotHideTheHouseholdsMethods(): void
    {
        // Like a category name, a payment method carries no financial
        // information, so ISOLATED narrows subscriptions and not this list.
        $id = $this->methods->create($this->owner, 'Joint card', null);

        $isolatedEditor = Scope::forMember(
            $this->editor->userId,
            false,
            (int) $this->editor->householdId,
            Role::Editor,
            IsolationMode::Isolated,
        );

        self::assertSame([$id], array_map(static fn ($m): int => $m->id, $this->methods->all($isolatedEditor)));

        // And it can be assigned to the isolated member's own subscription.
        $subscription = $this->createSubscription($isolatedEditor, ['payment_method_id' => (string) $id]);
        self::assertSame($id, $this->subscriptions->find($isolatedEditor, $subscription)?->paymentMethodId);
    }

    public function testNamesAreUniqueIgnoringCase(): void
    {
        $this->methods->create($this->owner, 'PayPal', null);

        $this->expectValidationError('name', fn () => $this->methods->create($this->owner, 'paypal', null));
        $this->expectValidationError('name', fn () => $this->methods->create($this->owner, '   ', null));
        $this->expectValidationError('colour', fn () => $this->methods->create($this->owner, 'Card', 'blue'));

        // The same name is fine in somebody else's household.
        $this->methods->create($this->elsewhere, 'PayPal', null);
        self::assertCount(1, $this->methods->all($this->elsewhere));
    }

    public function testADuplicateIsCaughtEvenAmongNamesThatContainIt(): void
    {
        // "card" is a substring of "Credit Card", "Debit Card" and "Gift Card";
        // the duplicate check must still find the "Card" that is the real one.
        $this->methods->seedDefaults($this->owner, 'en');
        $card = $this->methods->create($this->owner, 'Card', null);

        $this->expectValidationError('name', fn () => $this->methods->create($this->owner, 'card', null));

        // Renaming a method to its own name in another case is not a duplicate.
        $this->methods->update($this->owner, $card, 'CARD', null);
        self::assertSame('CARD', $this->methods->find($this->owner, $card)?->name);
    }

    public function testAnAssignmentPersistsAndClearingItWritesNull(): void
    {
        $card = $this->methods->create($this->owner, 'Joint card', null);
        $id = $this->createSubscription($this->owner, ['payment_method_id' => (string) $card]);

        $subscription = $this->subscriptions->find($this->owner, $id);
        self::assertNotNull($subscription);
        self::assertSame($card, $subscription->paymentMethodId);
        self::assertSame('Joint card', $subscription->paymentMethodName);

        // An edit that does not carry the field — an API client older than it —
        // leaves the assignment alone.
        $this->subscriptions->update($this->owner, $id, $this->input(['name' => 'Renamed']));
        self::assertSame($card, $this->subscriptions->find($this->owner, $id)?->paymentMethodId);

        // The form's "none" clears it.
        $this->subscriptions->update($this->owner, $id, $this->input(['payment_method_id' => '']));
        self::assertNull($this->subscriptions->find($this->owner, $id)?->paymentMethodId);
        self::assertNull($this->storedMethodId($id));
    }

    public function testAnotherHouseholdsMethodCannotBeAssigned(): void
    {
        $theirs = $this->methods->create($this->elsewhere, 'Their card', null);

        $this->expectValidationError(
            'payment_method_id',
            fn () => $this->createSubscription($this->owner, ['payment_method_id' => (string) $theirs]),
        );
    }

    public function testDeletingAMethodUnassignsItAndDeletesNoSubscription(): void
    {
        $card = $this->methods->create($this->owner, 'Joint card', null);
        $first = $this->createSubscription($this->owner, ['payment_method_id' => (string) $card]);
        $second = $this->createSubscription($this->owner, ['name' => 'Second', 'payment_method_id' => (string) $card]);

        $this->methods->delete($this->owner, $card);

        self::assertNull($this->methods->find($this->owner, $card));
        foreach ([$first, $second] as $id) {
            $subscription = $this->subscriptions->find($this->owner, $id);
            self::assertNotNull($subscription, 'a subscription must survive its payment method');
            self::assertNull($subscription->paymentMethodId);
        }
    }

    public function testALogoIsStoredUnderAnApplicationChosenNameAndRemovedWithItsMethod(): void
    {
        $id = $this->methods->create($this->owner, 'Joint card', null, FakeUpload::png('../../evil name.php.png'));
        $path = $this->methods->find($this->owner, $id)?->logoPath;

        self::assertNotNull($path);
        self::assertMatchesRegularExpression('#^assets/logos/[0-9a-f]{32}\.png$#', $path);
        $file = $this->logoDirectory . '/' . basename($path);
        self::assertFileExists($file);

        // Replacing it removes the old file.
        $this->methods->update($this->owner, $id, 'Joint card', null, FakeUpload::png());
        $replaced = (string) $this->methods->find($this->owner, $id)?->logoPath;
        self::assertNotSame($path, $replaced);
        self::assertFileDoesNotExist($file);

        // Renaming without a file keeps the picture.
        $this->methods->update($this->owner, $id, 'Joint account card', null);
        self::assertSame($replaced, $this->methods->find($this->owner, $id)?->logoPath);

        $this->methods->delete($this->owner, $id);
        self::assertFileDoesNotExist($this->logoDirectory . '/' . basename($replaced));
    }

    public function testClearingALogoRemovesTheFileAndKeepsTheMethod(): void
    {
        $id = $this->methods->create($this->owner, 'Joint card', null, FakeUpload::png());
        $path = (string) $this->methods->find($this->owner, $id)?->logoPath;

        $this->methods->clearLogo($this->owner, $id);

        self::assertNull($this->methods->find($this->owner, $id)?->logoPath);
        self::assertFileDoesNotExist($this->logoDirectory . '/' . basename($path));
    }

    public function testADisguisedOrOversizedLogoIsRefusedAndNothingIsCreated(): void
    {
        $disguised = FakeUpload::of("<?php echo 1;\n", 'logo.png');
        $this->expectValidationError(
            'logo',
            fn () => $this->methods->create($this->owner, 'Script', null, $disguised),
        );

        $oversized = FakeUpload::pngBytes() . str_repeat("\0", self::LOGO_MAX_BYTES);
        $this->expectValidationError(
            'logo',
            fn () => $this->methods->create($this->owner, 'Huge', null, FakeUpload::of($oversized, 'huge.png')),
        );

        self::assertSame([], $this->methods->all($this->owner));
        self::assertSame([], array_values(array_filter((array) glob($this->logoDirectory . '/*'))));
    }

    public function testByPaymentMethodSumsMonthlySpendAndLeavesOutOneOffs(): void
    {
        $card = $this->methods->create($this->owner, 'Joint card', '#123456');

        $this->createSubscription($this->owner, [
            'name' => 'A',
            'price' => '10.00',
            'payment_method_id' => (string) $card,
        ]);
        $this->createSubscription($this->owner, [
            'name' => 'B',
            'price' => '120.00',
            'billing_cycle' => 'yearly',
            'payment_method_id' => (string) $card,
        ]);
        $this->createSubscription($this->owner, ['name' => 'C', 'price' => '3.00']);
        // Neither a one-off nor a lifetime purchase is recurring spend.
        $this->createSubscription($this->owner, [
            'name' => 'Licence',
            'price' => '99.00',
            'subscription_type' => 'lifetime',
            'billing_cycle' => '',
            'next_payment_date' => '',
            'payment_method_id' => (string) $card,
        ]);

        $rows = $this->container->get(StatsService::class)->dashboard($this->owner)['by_payment_method'];

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertSame(1000 + 1000, $byName['Joint card']['monthly_minor']);
        self::assertSame(2, $byName['Joint card']['count']);
        self::assertSame('#123456', $byName['Joint card']['colour']);
        // The unassigned bucket is named by the empty string, for the screen
        // to put into words.
        self::assertSame(300, $byName['']['monthly_minor']);
        self::assertCount(2, $rows);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function createSubscription(Scope $scope, array $overrides = []): int
    {
        return $this->subscriptions->create($scope, $this->input($overrides));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Streaming',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ];
    }

    private function storedMethodId(int $subscriptionId): ?int
    {
        $platform = $this->db->platform();
        $value = $this->db->fetchValue(
            'SELECT ' . $platform->quoteIdentifier('payment_method_id')
            . ' FROM ' . $platform->quoteIdentifier('subscriptions')
            . ' WHERE ' . $platform->quoteIdentifier('id') . ' = :id',
            ['id' => $subscriptionId],
        );

        return $value === null ? null : (int) $value;
    }

    private function expectValidationError(string $field, callable $attempt): void
    {
        try {
            $attempt();
            self::fail(sprintf('Expected a validation error on "%s".', $field));
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors());
        }
    }

    private function buildContainer(): ContainerInterface
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['database'] = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);

        $builder->addDefinitions([
            LogoStorage::class => factory(fn (): LogoStorage => new LogoStorage(
                $this->logoDirectory,
                self::LOGO_MAX_BYTES,
            )),
        ]);

        return $builder->build();
    }
}
