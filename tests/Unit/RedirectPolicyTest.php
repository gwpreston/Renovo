<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\BlockedTargetException;
use App\Http\RedirectPolicy;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\UriFactory;

/**
 * Where a guarded request may be redirected.
 *
 * The rule under test is stricter than a browser's, and the reason is the
 * request's own headers: they carry a Gotify token or a Slack bot token, and a
 * redirect is somebody else's server asking us to send them somewhere new.
 */
final class RedirectPolicyTest extends TestCase
{
    private RedirectPolicy $policy;
    private UriFactory $uris;

    protected function setUp(): void
    {
        $this->uris = new UriFactory();
        $this->policy = new RedirectPolicy($this->uris);
    }

    public function testASameHostRedirectIsFollowed(): void
    {
        $next = $this->policy->next(
            $this->uris->createUri('https://gotify.example.com/message'),
            'https://gotify.example.com/v2/message',
        );

        self::assertSame('https://gotify.example.com/v2/message', (string) $next);
    }

    public function testARootRelativeLocationIsResolvedAgainstTheCurrentUrl(): void
    {
        $next = $this->policy->next(
            $this->uris->createUri('https://gotify.example.com/a/b'),
            '/message',
        );

        self::assertSame('https://gotify.example.com/message', (string) $next);
    }

    public function testAPathRelativeLocationIsResolvedAgainstTheCurrentDirectory(): void
    {
        $next = $this->policy->next(
            $this->uris->createUri('https://gotify.example.com/api/v1/send'),
            'send2',
        );

        self::assertSame('https://gotify.example.com/api/v1/send2', (string) $next);
    }

    public function testASchemeRelativeLocationKeepsTheCurrentScheme(): void
    {
        $next = $this->policy->next(
            $this->uris->createUri('https://gotify.example.com/message'),
            '//gotify.example.com/other',
        );

        self::assertSame('https://gotify.example.com/other', (string) $next);
    }

    public function testACrossHostRedirectIsRefused(): void
    {
        $this->expectException(BlockedTargetException::class);

        $this->policy->next(
            $this->uris->createUri('https://gotify.example.com/message'),
            'https://attacker.example/collect',
        );
    }

    public function testARedirectToADifferentPortIsRefused(): void
    {
        // A different port is a different service, and a service that did not
        // issue the token has no business receiving it.
        $this->expectException(BlockedTargetException::class);

        $this->policy->next(
            $this->uris->createUri('https://gotify.example.com/message'),
            'https://gotify.example.com:8443/message',
        );
    }

    public function testADowngradeToPlainHttpIsRefused(): void
    {
        $this->expectException(BlockedTargetException::class);

        $this->policy->next(
            $this->uris->createUri('https://gotify.example.com/message'),
            'http://gotify.example.com/message',
        );
    }

    public function testARedirectCarryingCredentialsIsRefused(): void
    {
        $this->expectException(BlockedTargetException::class);

        $this->policy->next(
            $this->uris->createUri('https://gotify.example.com/message'),
            'https://user:pass@gotify.example.com/message',
        );
    }

    public function testAnEmptyLocationIsRefused(): void
    {
        $this->expectException(BlockedTargetException::class);

        $this->policy->next($this->uris->createUri('https://gotify.example.com/message'), '  ');
    }

    public function testMethodChangingStatuses(): void
    {
        self::assertTrue(RedirectPolicy::becomesGet(303, 'POST'));
        self::assertTrue(RedirectPolicy::becomesGet(302, 'POST'));
        self::assertTrue(RedirectPolicy::becomesGet(301, 'POST'));
        self::assertFalse(RedirectPolicy::becomesGet(307, 'POST'));
        self::assertFalse(RedirectPolicy::becomesGet(308, 'POST'));
        self::assertFalse(RedirectPolicy::becomesGet(302, 'GET'));
    }

    public function testOnlyRedirectStatusesCount(): void
    {
        self::assertTrue(RedirectPolicy::isRedirect(301));
        self::assertTrue(RedirectPolicy::isRedirect(308));
        self::assertFalse(RedirectPolicy::isRedirect(200));
        self::assertFalse(RedirectPolicy::isRedirect(304));
    }
}
