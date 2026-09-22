<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The shape of the new/edit subscription form.
 *
 * The layout itself is CSS and not assertable here. What is assertable is the
 * structure the CSS is written against, and those are the parts that regress
 * silently when somebody adds a field in a hurry: that the sections exist and
 * are siblings rather than nested, that every hint is actually pointed at by
 * the control it explains, and that no label is a literal in the template.
 *
 * All three are asserted on both the page and the quick-add dialog's copy of
 * it, because they are one template and the bug worth catching is the day they
 * stop being.
 */
final class SubscriptionFormLayoutTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $householdId;
    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->setDemoMode(false);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);

        $this->subscriptionId = (new SubscriptionRepository($this->db))->create(
            Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared),
            [
                'name' => 'Streaming',
                'price_minor' => 999,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-10-01',
                'start_date' => '2025-01-01',
                'is_active' => true,
                'owner_user_id' => $this->ownerId,
            ],
            [],
        );

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function forms(): array
    {
        return [
            ['/subscriptions/new'],
            ['/subscriptions/{id}/edit'],
        ];
    }

    /**
     * @dataProvider forms
     */
    public function testTheFormIsBuiltFromSections(string $path): void
    {
        $form = $this->form($this->get($path));

        $legends = [];
        foreach ($this->query($form, './/fieldset[@class="form-section"]/legend') as $legend) {
            $legends[] = trim($legend->textContent);
        }

        self::assertSame(
            ['What it is', 'What it costs', 'When it’s billed', 'Free trial', 'Who it’s for'],
            $legends,
            $path . ' does not render the five sections in order.',
        );
    }

    /**
     * A nested fieldset is announced through every legend above it, so "Trial
     * ends" would arrive as "Free trial, when it's billed, Trial ends". The
     * sections are siblings for that reason, and this is what holds them there.
     */
    public function testNoSectionIsNestedInsideAnother(): void
    {
        $form = $this->form($this->get('/subscriptions/new'));

        self::assertCount(
            0,
            $this->query($form, './/fieldset[@class="form-section"]//fieldset'),
            'A form section contains another: its legends will be announced together.',
        );
    }

    /**
     * The "belongs to" select, where writes are confined to their owner.
     *
     * Two things have to move together here and the template is the only place
     * they can come apart: the control is disabled, and it names a hint saying
     * why. The hint's id is in `aria-describedby` unconditionally on that same
     * branch, so a note drawn on a narrower condition is a description pointing
     * at nothing.
     *
     * It is asserted through the rendered page rather than by reading the
     * template because of how this fails. Twig is not in strict mode, so
     * `scope.somethingThatNoLongerExists` is null rather than an error: rename
     * the method behind it and the select quietly stops being disabled, on
     * every instance, with nothing anywhere saying so. That is not a
     * hypothetical — it is what this test was written for.
     */
    public function testTheOwnerSelectIsDisabledWhereWritesAreConfinedToTheirOwner(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $container->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $form = $this->form($this->get('/subscriptions/new'));

        $select = $this->query($form, './/select[@id="owner_user_id"]');
        self::assertCount(1, $select, 'The form no longer offers an owner select at all.');
        self::assertTrue(
            $select[0]->hasAttribute('disabled'),
            'The owner select is editable on an instance that confines writes to their owner.',
        );

        self::assertCount(
            1,
            $this->query($form, './/*[@id="owner_user_id-hint"]'),
            'The select is described by a hint that was not rendered.',
        );
    }

    /**
     * @dataProvider forms
     */
    public function testEveryHintIsPointedAtByTheControlItExplains(string $path): void
    {
        $this->assertDescriptionsResolve($this->get($path), $path);
    }

    /**
     * The same assertion again, over a form that came back rejected.
     *
     * Roughly fifteen fields carry their error id behind an `{% if %}`, so on
     * a form that validated cleanly none of those branches has rendered at
     * all and the check above has never seen one. A `describedby` naming an id
     * that does not exist is announced as nothing, which is worse than no hint
     * — and it is a typo away at every one of those fifteen. This is the case
     * that actually reads them.
     */
    public function testTheDescriptionsStillResolveWhenTheFormComesBackRejected(): void
    {
        $rejected = $this->postInvalid();

        self::assertStringContainsString('field-error', $rejected, 'Nothing was rejected, so no branch rendered.');
        $this->assertDescriptionsResolve($rejected, 'the rejected form');
    }

    /**
     * Every error paragraph the template can render, against the control that
     * has to name it.
     *
     * The test above renders six of the seventeen — a rejected create only
     * reaches the fields a rejected create can reach, and nothing a request
     * can post makes the logo, the category and the two member selects fail at
     * once. The other eleven are a typo away from describing a control by an
     * id that is never on the page, which a reader hears as nothing at all.
     *
     * So this one reads the template rather than a rendering of it. That is
     * deliberately the white-box test it appears to be: the pairing is a
     * property of the source, and the source is the only place all seventeen
     * exist together.
     */
    public function testEveryErrorTheTemplateCanRenderIsNamedByItsControl(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/subscriptions/form.twig');
        self::assertIsString($template);

        preg_match_all('/class="field-error" id="([^"]+)"/', $template, $defined);
        preg_match_all('/aria-describedby="([^"]*)"/', $template, $referenced);

        $named = [];
        foreach ($referenced[1] as $list) {
            foreach (preg_split('/\s+|\{%[^%]*%\}/', $list) ?: [] as $id) {
                if (str_ends_with($id, '-error')) {
                    $named[$id] = true;
                }
            }
        }

        self::assertNotEmpty($defined[1], 'The form renders no field errors at all.');

        self::assertSame(
            [],
            array_values(array_diff($defined[1], array_keys($named))),
            'The form can render an error no control points at.',
        );

        self::assertSame(
            [],
            array_values(array_diff(array_keys($named), $defined[1])),
            'A control points at an error paragraph the form never renders.',
        );
    }

    private function assertDescriptionsResolve(string $html, string $path): void
    {
        $form = $this->form($html);

        $described = [];
        foreach ($this->query($form, './/*[@aria-describedby]') as $control) {
            foreach (preg_split('/\s+/', trim($control->getAttribute('aria-describedby'))) ?: [] as $id) {
                if ($id !== '') {
                    $described[$id] = true;
                }
            }
        }

        $orphans = [];
        foreach ($this->query($form, './/p[@class="hint"][@id]') as $hint) {
            if (!isset($described[$hint->getAttribute('id')])) {
                $orphans[] = $hint->getAttribute('id');
            }
        }

        self::assertSame([], $orphans, $path . ' has hints no control refers to.');

        // And the other way: a describedby naming an id the page never renders
        // is announced as nothing at all, which is worse than no hint.
        $missing = [];
        foreach (array_keys($described) as $id) {
            if ($this->query($form, sprintf('.//*[@id="%s"]', $id)) === []) {
                $missing[] = $id;
            }
        }

        self::assertSame([], $missing, $path . ' describes controls by ids it does not render.');
    }

    /**
     * Every hint on this form had no `aria-describedby` at all until Phase 14,
     * so a reader heard "Days between payments, spin button" and never "Only
     * used when the cycle is Custom". This asserts the wiring is there rather
     * than merely consistent, which the test above would pass with none of it.
     */
    public function testTheHintsThatExplainAControlAreWiredToIt(): void
    {
        $form = $this->form($this->get('/subscriptions/new'));

        foreach (['cycle_days', 'subscription_type', 'start_date', 'reminder_days', 'tags'] as $field) {
            $control = $this->query($form, sprintf('.//*[@id="%s"]', $field))[0] ?? null;
            self::assertInstanceOf(DOMElement::class, $control, $field . ' is not on the form.');

            self::assertStringContainsString(
                $field . '-hint',
                $control->getAttribute('aria-describedby'),
                $field . ' has a hint beside it that it does not point at.',
            );
        }
    }

    /**
     * @dataProvider forms
     */
    public function testTheSubmitLabelComesFromTheCatalogue(string $path): void
    {
        $button = $this->query($this->form($this->get($path)), './/button[@type="submit"]')[0] ?? null;
        self::assertInstanceOf(DOMElement::class, $button, $path . ' has no submit button.');

        self::assertSame(
            $path === '/subscriptions/new' ? 'Add subscription' : 'Save changes',
            trim($button->textContent),
        );
    }

    /**
     * The quick-add dialog loads this same template through htmx. The sections
     * and the wiring have to survive that, or the dialog quietly becomes the
     * short version of the form that the template comment says it is not.
     */
    public function testTheDialogLoadsTheSameFormAsThePage(): void
    {
        $fragment = $this->body($this->get('/subscriptions/new', ['HX-Request' => 'true']));

        self::assertStringNotContainsString('<html', $fragment, 'The dialog was sent a whole document.');
        self::assertStringContainsString('form-layout', $fragment);

        self::assertSame(
            5,
            substr_count($fragment, 'class="form-section"'),
            'The dialog renders a different number of sections from the page.',
        );
    }

    /**
     * The form carries its own measure and its own container, and the grid
     * inside it is queried against that container rather than the viewport.
     * The dialog is 42rem wide on a window that may be five times that, so a
     * viewport query is the one thing this layout must not be built on.
     */
    public function testTheFormCarriesTheContainerItsGridIsMeasuredAgainst(): void
    {
        $form = $this->form($this->get('/subscriptions/new'));

        self::assertStringContainsString('form-layout', $form->getAttribute('class'));
        self::assertNotEmpty(
            $this->query($form, './/div[@class="field-grid"]'),
            'The form has no paired fields, so the container has nothing to measure.',
        );
    }

    /**
     * @return list<DOMElement>
     */
    private function query(DOMElement $context, string $expression): array
    {
        $document = $context->ownerDocument;
        self::assertNotNull($document);

        $nodes = (new DOMXPath($document))->query($expression, $context);
        self::assertNotFalse($nodes);

        $found = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private function form(string $html): DOMElement
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        $form = (new DOMXPath($document))->query('//form[contains(@class, "form-layout")]')?->item(0);

        if (!$form instanceof DOMElement) {
            self::fail('The page renders no subscription form.');
        }

        return $form;
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, array $headers = []): string
    {
        return $this->body($this->request($path, $headers));
    }

    /**
     * A create that fails validation, which the controller answers by
     * rendering this same form again at 422 with the errors in place.
     *
     * The fields are chosen to light up several branches at once: a missing
     * name, a price that is not a number, a cycle length outside its range and
     * a date that is not one.
     */
    private function postInvalid(): string
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/subscriptions')
            ->withParsedBody([
                'name' => '',
                'price' => 'not a number',
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'custom',
                'cycle_days' => '99999',
                'next_payment_date' => 'not a date',
                'trial_end_date' => 'not a date',
                'converts_to_price' => 'not a number',
                'converts_to_cycle_days' => '99999',
                'notice_period_amount' => '99999',
                'notice_period_unit' => 'days',
                'reminder_days' => 'not days',
                'website_url' => 'not a url',
                'is_active' => '1',
                CsrfTokenManager::FIELD_NAME => $container->get(CsrfTokenManager::class)->token(),
            ]);

        $response = $this->app->handle($request);
        self::assertSame(422, $response->getStatusCode(), 'The invalid post was accepted.');

        return $this->body($response);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $path, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            str_replace('{id}', (string) $this->subscriptionId, $path),
        );

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }

    private function body(ResponseInterface|string $response): string
    {
        return is_string($response) ? $response : (string) $response->getBody();
    }
}
