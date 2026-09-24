<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Entity\Subscription;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Support\MoneyFormatter;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The subscription form as Phase 22 arranges it: the prototype's fields always
 * shown, every older one under More details, the split saved with the row, the
 * reminder chips over the three-state `reminder_days`, and the edit page's own
 * sections after the form.
 */
final class SubscriptionFormTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $partnerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new DateTimeImmutable();
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->partnerId = $users->create('partner@example.test', 'Partner', 'hash', false, $now);
        $this->householdId = (new HouseholdRepository($this->db))->create('House', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->partnerId, Role::Editor);

        $this->signIn($this->ownerId);
    }

    // ------------------------------------------------------------------ split

    public function testACustomSplitsSharesSumExactlyToThePrice(): void
    {
        $response = $this->request('POST', '/subscriptions', $this->form([
            'price' => '10.00',
            'split_mode' => 'custom',
            'shares' => [
                (string) $this->ownerId => '1',
                (string) $this->editorId => '1',
                (string) $this->partnerId => '1',
            ],
        ]));
        self::assertSame(302, $response->getStatusCode());

        $subscription = $this->onlySubscription();
        self::assertSame(SplitMode::Custom, $subscription->splitMode);

        $splits = $this->container()->get(SplitService::class);
        $shares = $splits->sharesOf($subscription, $splits->participants($this->ownerScope(), $subscription->id));

        // £10 three ways is 3.34 / 3.33 / 3.33, never three times 3.33.
        self::assertCount(3, $shares);
        $amounts = array_map(static fn (array $share): int => $share['amount']->amountMinor, $shares);
        self::assertSame(1000, array_sum($amounts));
    }

    public function testAnEqualSplitIsBetweenTheMembersTicked(): void
    {
        $this->request('POST', '/subscriptions', $this->form([
            'split_mode' => 'equal',
            'split_with' => [(string) $this->ownerId, (string) $this->editorId],
        ]));

        $subscription = $this->onlySubscription();
        self::assertSame(SplitMode::Equal, $subscription->splitMode);

        $participants = $this->container()->get(SplitService::class)
            ->participants($this->ownerScope(), $subscription->id);
        $ids = array_map(static fn ($split): int => $split->userId, $participants);
        sort($ids);
        self::assertSame([$this->ownerId, $this->editorId], $ids);
    }

    public function testOnlyMeWithASplitIsRefusedAndNothingIsSaved(): void
    {
        $response = $this->request('POST', '/subscriptions', $this->form([
            'visibility' => 'payer',
            'split_mode' => 'equal',
            'split_with' => [(string) $this->ownerId, (string) $this->editorId],
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('visibility-error', (string) $response->getBody());
        self::assertSame([], $this->all());
    }

    public function testOneSaveCanTakeTheSplitOffAndMakeTheRowPrivate(): void
    {
        $this->request('POST', '/subscriptions', $this->form([
            'split_mode' => 'equal',
            'split_with' => [(string) $this->ownerId, (string) $this->editorId],
        ]));
        $subscription = $this->onlySubscription();

        $response = $this->request('POST', '/subscriptions/' . $subscription->id, $this->form([
            'visibility' => 'payer',
            'split_mode' => 'none',
        ]));

        self::assertSame(302, $response->getStatusCode());
        $saved = $this->onlySubscription();
        self::assertSame(SplitMode::None, $saved->splitMode);
        self::assertTrue($saved->isPrivate());
    }

    public function testAFailedSaveLeavesTheSplitAsItWas(): void
    {
        $this->request('POST', '/subscriptions', $this->form([
            'split_mode' => 'equal',
            'split_with' => [(string) $this->ownerId, (string) $this->editorId],
        ]));
        $subscription = $this->onlySubscription();

        // The split is taken off and the row is invalid: the transaction that
        // would have removed the split rolls back with the rest.
        $response = $this->request('POST', '/subscriptions/' . $subscription->id, $this->form([
            'name' => '',
            'split_mode' => 'none',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(SplitMode::Equal, $this->onlySubscription()->splitMode);
    }

    public function testAFormWithoutTheSplitControlsLeavesTheSplitAlone(): void
    {
        $this->request('POST', '/subscriptions', $this->form([
            'split_mode' => 'equal',
            'split_with' => [(string) $this->ownerId, (string) $this->editorId],
        ]));
        $subscription = $this->onlySubscription();

        $form = $this->form(['name' => 'Renamed']);
        $this->request('POST', '/subscriptions/' . $subscription->id, $form);

        self::assertSame('Renamed', $this->onlySubscription()->name);
        self::assertSame(SplitMode::Equal, $this->onlySubscription()->splitMode);
    }

    public function testASplitRowOffersOnlyMeClosedUntilTheSplitIsTakenOff(): void
    {
        $this->request('POST', '/subscriptions', $this->form([
            'split_mode' => 'equal',
            'split_with' => [(string) $this->ownerId, (string) $this->editorId],
        ]));
        $subscription = $this->onlySubscription();

        $edit = $this->formElement($this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/edit')));
        $onlyMe = $this->xpath($edit, './/input[@name="visibility"][@value="payer"]')[0];
        self::assertTrue($onlyMe->hasAttribute('disabled'));
        // Closed by the split this member can change, not locked for good.
        self::assertFalse($onlyMe->hasAttribute('data-locked'));
    }

    // -------------------------------------------------------------- reminders

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function reminderStates(): array
    {
        return [
            'my defaults' => [['reminder_mode' => 'default'], null],
            'never' => [['reminder_mode' => 'never'], ''],
            'chosen days' => [['reminder_mode' => 'days', 'reminder_day' => ['1', '7', '14']], '14,7,1'],
        ];
    }

    /**
     * @dataProvider reminderStates
     * @param array<string, mixed> $fields
     */
    public function testRemindersRoundTripEachOfTheirThreeStates(array $fields, ?string $stored): void
    {
        $this->request('POST', '/subscriptions', $this->form($fields));
        $subscription = $this->onlySubscription();
        self::assertSame($stored, $subscription->reminderDays);

        // And the edit form draws the state it was saved in, so saving it again
        // unchanged changes nothing.
        $edit = $this->formElement($this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/edit')));
        $checked = $this->xpath($edit, './/input[@name="reminder_mode"][@checked]');
        self::assertCount(1, $checked);
        self::assertSame($fields['reminder_mode'], $checked[0]->getAttribute('value'));

        $this->request('POST', '/subscriptions/' . $subscription->id, $this->form($fields));
        self::assertSame($stored, $this->onlySubscription()->reminderDays);
    }

    public function testADayTheChipsDoNotOfferIsKeptByAnEdit(): void
    {
        // Set before the chips existed, or through the API.
        $this->request('POST', '/subscriptions', $this->form(['reminder_mode' => 'days', 'reminder_day' => ['14']]));
        $subscription = $this->onlySubscription();
        $this->db->execute('UPDATE subscriptions SET reminder_days = :days WHERE id = :id', [
            'days' => '60,14',
            'id' => $subscription->id,
        ]);

        $edit = $this->formElement($this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/edit')));
        $checked = array_map(
            static fn (DOMElement $input): string => $input->getAttribute('value'),
            $this->xpath($edit, './/input[@name="reminder_day[]"][@checked]'),
        );
        self::assertSame(['14', '60'], $checked);

        // Posting back what the form drew keeps the 60.
        $this->request('POST', '/subscriptions/' . $subscription->id, $this->form([
            'reminder_mode' => 'days',
            'reminder_day' => $checked,
        ]));
        self::assertSame('60,14', $this->onlySubscription()->reminderDays);
    }

    public function testChoosingDaysButNoneIsRefused(): void
    {
        $response = $this->request('POST', '/subscriptions', $this->form(['reminder_mode' => 'days']));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('reminder_days-error', (string) $response->getBody());
    }

    // -------------------------------------------------------- cycle and type

    public function testACustomCycleAndATypePersist(): void
    {
        $this->request('POST', '/subscriptions', $this->form([
            'billing_cycle' => 'custom_days',
            'cycle_days' => '45',
        ]));
        $subscription = $this->onlySubscription();
        self::assertSame('custom_days', $subscription->billingCycle?->value);
        self::assertSame(45, $subscription->cycleDays);

        $this->request('POST', '/subscriptions/' . $subscription->id, $this->form([
            'subscription_type' => 'lifetime',
        ]));
        self::assertSame('lifetime', $this->onlySubscription()->type->value);

        $edit = $this->formElement($this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/edit')));
        self::assertCount(1, $this->xpath($edit, './/input[@name="subscription_type"][@value="lifetime"][@checked]'));
    }

    // --------------------------------------------------------- conversion note

    public function testTheConversionNoteIsTheServersForANonBaseCurrency(): void
    {
        $money = $this->container()->get(MoneyFormatter::class);

        $note = $this->body($this->request('GET', '/subscriptions/conversion-note?price=10.00&currency=EUR'));
        self::assertStringContainsString('id="price-conversion"', $note);
        self::assertStringContainsString('≈ ' . $money->formatMinor(500, 'GBP'), $note);

        // And on the form itself, before any script has run.
        $this->request('POST', '/subscriptions', $this->form(['price' => '10.00', 'currency' => 'EUR']));
        $subscription = $this->onlySubscription();
        $edit = $this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/edit'));
        self::assertStringContainsString('≈ ' . $money->formatMinor(500, 'GBP'), $edit);
    }

    public function testTheConversionNoteSaysNothingForTheBaseCurrencyOrNonsense(): void
    {
        foreach (['price=10.00&currency=GBP', 'price=lots&currency=EUR', 'price=&currency=EUR'] as $query) {
            $note = $this->body($this->request('GET', '/subscriptions/conversion-note?' . $query));
            self::assertMatchesRegularExpression(
                '/<p class="conversion-note" id="price-conversion"[^>]*><\/p>/',
                $note,
                $query,
            );
        }

        self::assertStringContainsString(
            'No exchange rate from XOF',
            $this->body($this->request('GET', '/subscriptions/conversion-note?price=10&currency=XOF')),
        );
    }

    public function testTheCurrencySelectOffersEveryIsoCurrency(): void
    {
        $form = $this->formElement($this->body($this->request('GET', '/subscriptions/new')));

        self::assertGreaterThan(150, count($this->xpath($form, './/select[@name="currency"]/option')));
        self::assertCount(1, $this->xpath($form, './/select[@name="currency"]/option[@value="XOF"]'));
    }

    // ------------------------------------------------------ one definition

    public function testQuickAddAndTheFullPageRenderIdenticalFields(): void
    {
        $page = $this->fieldNames($this->body($this->request('GET', '/subscriptions/new')));
        $dialog = $this->fieldNames($this->body(
            $this->request('GET', '/subscriptions/new', [], ['HX-Request' => 'true']),
        ));

        self::assertNotEmpty($page);
        self::assertSame($page, $dialog);

        $expected = [
            'name', 'plan', 'price', 'currency', 'category_id', 'billing_cycle', 'subscription_type',
            'next_payment_date', 'payment_method_id', 'reminder_mode', 'owner_user_id', 'split_mode',
            'visibility', 'is_trial', 'trial_end_date', 'converts_to_price', 'notice_period_amount', 'tags',
            'website_url', 'logo', 'start_date', 'notes', 'payer_user_id', 'is_active',
        ];
        foreach ($expected as $field) {
            self::assertContains($field, $page, $field . ' is missing from the form');
        }
    }

    public function testTheLessUsedFieldsSitUnderMoreDetailsClosed(): void
    {
        $form = $this->formElement($this->body($this->request('GET', '/subscriptions/new')));

        $details = $this->xpath($form, './/details[contains(@class, "more-details")]');
        self::assertCount(1, $details);
        self::assertFalse($details[0]->hasAttribute('open'));

        foreach (['notice_period_amount', 'tags', 'website_url', 'logo', 'start_date', 'notes'] as $field) {
            self::assertCount(
                1,
                $this->xpath($details[0], './/*[@name="' . $field . '"]'),
                $field . ' is not under More details',
            );
        }
    }

    public function testMoreDetailsOpensWhenOneOfItsFieldsComesBackWithAnError(): void
    {
        $response = $this->request('POST', '/subscriptions', $this->form(['notes' => str_repeat('x', 5001)]));

        self::assertSame(422, $response->getStatusCode());
        $form = $this->formElement($this->body($response));
        self::assertTrue($this->xpath($form, './/details[contains(@class, "more-details")]')[0]->hasAttribute('open'));
    }

    // ------------------------------------------------------- edit-only parts

    public function testTheEditPageCarriesPriceHistoryAttachmentsAndTheEndings(): void
    {
        $this->request('POST', '/subscriptions', $this->form([]));
        $subscription = $this->onlySubscription();

        $edit = $this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/edit'));

        self::assertStringContainsString('action="/subscriptions/' . $subscription->id . '/price-changes"', $edit);
        self::assertStringContainsString('action="/subscriptions/' . $subscription->id . '/attachments"', $edit);
        self::assertStringContainsString('action="/subscriptions/' . $subscription->id . '/cancel"', $edit);
        self::assertStringContainsString('action="/subscriptions/' . $subscription->id . '/delete"', $edit);

        // None of those forms is inside the subscription form.
        $form = $this->formElement($edit);
        self::assertSame([], $this->xpath($form, './/form'));

        // And the new form has none of them.
        $new = $this->body($this->request('GET', '/subscriptions/new'));
        self::assertStringNotContainsString('/price-changes', $new);
        self::assertStringNotContainsString('/attachments', $new);
    }

    public function testSchedulingAPriceFromTheEditPageComesBackToIt(): void
    {
        $this->request('POST', '/subscriptions', $this->form([]));
        $subscription = $this->onlySubscription();

        $response = $this->request('POST', '/subscriptions/' . $subscription->id . '/price-changes', [
            'price' => '12.99',
            'currency' => 'GBP',
            'effective_from' => (new DateTimeImmutable('+40 days'))->format('Y-m-d'),
            'return_to' => '/subscriptions/' . $subscription->id . '/edit',
        ]);

        self::assertSame('/subscriptions/' . $subscription->id . '/edit', $response->getHeaderLine('Location'));
        self::assertNotNull(
            $this->container()->get(PriceHistoryService::class)
                ->nextScheduledChange($this->ownerScope(), $subscription->id),
        );

        // Newest first, so the scheduled change leads the history.
        $edit = $this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/edit'));
        self::assertLessThan(
            (int) strpos($edit, '£9.99', (int) strpos($edit, 'price-history-heading')),
            (int) strpos($edit, '£12.99', (int) strpos($edit, 'price-history-heading')),
        );
    }

    public function testAReturnAddressIsNeverAnywhereButThisSubscriptionsTwoPages(): void
    {
        $this->request('POST', '/subscriptions', $this->form([]));
        $subscription = $this->onlySubscription();

        foreach (['https://evil.example/', '//evil.example', '/subscriptions/999/edit'] as $target) {
            $response = $this->request('POST', '/subscriptions/' . $subscription->id . '/price-changes', [
                'price' => '12.99',
                'currency' => 'GBP',
                'effective_from' => (new DateTimeImmutable('+40 days'))->format('Y-m-d'),
                'return_to' => $target,
            ]);

            self::assertSame(
                '/subscriptions/' . $subscription->id . '/money',
                $response->getHeaderLine('Location'),
                $target,
            );
        }

        $cancelled = $this->request('POST', '/subscriptions/' . $subscription->id . '/cancel', [
            'return_to' => '/subscriptions/' . $subscription->id . '/edit',
        ]);
        self::assertSame('/subscriptions/' . $subscription->id . '/edit', $cancelled->getHeaderLine('Location'));
    }

    public function testTheCostPageIsReadOnlyAndPointsAWriterAtTheEditForm(): void
    {
        $this->request('POST', '/subscriptions', $this->form([]));
        $subscription = $this->onlySubscription();

        $money = $this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/money'));

        self::assertStringNotContainsString('action="/subscriptions/' . $subscription->id . '/price-changes"', $money);
        self::assertStringNotContainsString('action="/subscriptions/' . $subscription->id . '/split"', $money);
        self::assertStringNotContainsString('action="/subscriptions/' . $subscription->id . '/attachments"', $money);
        self::assertStringContainsString('href="/subscriptions/' . $subscription->id . '/edit"', $money);
        // Usage is still recorded here.
        self::assertStringContainsString('action="/subscriptions/' . $subscription->id . '/usage"', $money);
    }

    public function testTheEditHeadingNamesTheSubscription(): void
    {
        $this->request('POST', '/subscriptions', $this->form(['name' => 'Netflix']));
        $subscription = $this->onlySubscription();

        self::assertStringContainsString(
            'Edit Netflix',
            $this->body($this->request('GET', '/subscriptions/' . $subscription->id . '/edit')),
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides): array
    {
        return $overrides + [
            'name' => 'Streaming',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'is_active' => '1',
            'visibility' => 'household',
            'owner_user_id' => (string) $this->ownerId,
        ];
    }

    /**
     * The distinct names of every control inside the subscription form.
     *
     * @return list<string>
     */
    private function fieldNames(string $html): array
    {
        $names = [];
        foreach ($this->xpath($this->formElement($html), './/*[@name]') as $control) {
            $names[] = (string) preg_replace('/\[.*$/', '', $control->getAttribute('name'));
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    private function formElement(string $html): DOMElement
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        $form = (new DOMXPath($document))->query('//form[@id="subscription-form"]')?->item(0);
        if (!$form instanceof DOMElement) {
            self::fail('The page renders no subscription form.');
        }

        return $form;
    }

    /**
     * @return list<DOMElement>
     */
    private function xpath(DOMElement $context, string $expression): array
    {
        $document = $context->ownerDocument;
        self::assertNotNull($document);

        $found = [];
        foreach ((new DOMXPath($document))->query($expression, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private function onlySubscription(): Subscription
    {
        $all = $this->all();
        self::assertCount(1, $all);

        return $all[0];
    }

    /**
     * @return list<Subscription>
     */
    private function all(): array
    {
        return $this->container()->get(SubscriptionService::class)->allForStats($this->ownerScope(), activeOnly: false);
    }

    private function ownerScope(): Scope
    {
        return Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $body = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($method !== 'GET') {
            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
