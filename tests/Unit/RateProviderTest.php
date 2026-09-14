<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\ExchangeRate;
use App\Service\ExchangeRate\ExchangeRateHostProvider;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FixerProvider;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRate\RateProviderException;
use App\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;

/**
 * Each provider against a recorded response in its own published shape, and
 * against the ways a provider can let us down.
 *
 * No network is involved: the HTTP client is faked, which is the point of the
 * providers depending on ClientInterface rather than on the concrete client.
 */
final class RateProviderTest extends TestCase
{
    public function testFrankfurterParsesItsResponse(): void
    {
        $http = FakeHttpClient::returningJson([
            'amount' => 1.0,
            'base' => 'GBP',
            'date' => '2026-09-11',
            'rates' => ['EUR' => 1.1723, 'USD' => 1.2684, 'JPY' => 190.5],
        ]);

        $table = $this->frankfurter($http)->fetch('GBP', null);

        self::assertSame('GBP', $table->baseCurrency);
        self::assertSame('2026-09-11', $table->asOfDate?->format('Y-m-d'));
        self::assertSame(ExchangeRate::scaleFromDecimalString('1.1723'), $table->rates['EUR']);
        self::assertSame(ExchangeRate::scaleFromDecimalString('190.5'), $table->rates['JPY']);

        // The base is always present at exactly 1, so no lookup needs a
        // special case for it.
        self::assertSame(ExchangeRate::SCALE, $table->rates['GBP']);

        self::assertSame(['https://api.frankfurter.app/latest?from=GBP'], $http->requestedUrls);
    }

    public function testFrankfurterNeedsNoKey(): void
    {
        self::assertFalse($this->frankfurter(FakeHttpClient::returning('{}'))->requiresApiKey());
    }

    public function testFrankfurterTrustsTheBaseTheFeedActuallyUsed(): void
    {
        // Asked for a currency the feed does not serve as a base, it answers in
        // its own. Taking the feed's word for it is what stops the rates being
        // stored under a label they do not match.
        $http = FakeHttpClient::returningJson([
            'base' => 'EUR',
            'date' => '2026-09-11',
            'rates' => ['GBP' => 0.853],
        ]);

        self::assertSame('EUR', $this->frankfurter($http)->fetch('XXX', null)->baseCurrency);
    }

    public function testExchangeRateHostUnpacksConcatenatedPairKeys(): void
    {
        $http = FakeHttpClient::returningJson([
            'success' => true,
            'source' => 'GBP',
            'quotes' => ['GBPEUR' => 1.1723, 'GBPUSD' => 1.2684],
        ]);

        $table = $this->exchangeRateHost($http)->fetch('GBP', 'secret-key');

        self::assertSame('GBP', $table->baseCurrency);
        self::assertSame(ExchangeRate::scaleFromDecimalString('1.1723'), $table->rates['EUR']);
        self::assertStringContainsString('access_key=secret-key', $http->requestedUrls[0]);
        self::assertStringContainsString('source=GBP', $http->requestedUrls[0]);
    }

    public function testExchangeRateHostRefusesToRunWithoutAKey(): void
    {
        $this->expectException(RateProviderException::class);
        $this->expectExceptionMessage('needs an API key');

        $this->exchangeRateHost(FakeHttpClient::returning('{}'))->fetch('GBP', null);
    }

    public function testExchangeRateHostSurfacesTheProvidersOwnError(): void
    {
        $http = FakeHttpClient::returningJson([
            'success' => false,
            'error' => ['code' => 101, 'info' => 'Your access key is invalid.'],
        ]);

        $this->expectException(RateProviderException::class);
        $this->expectExceptionMessage('Your access key is invalid.');

        $this->exchangeRateHost($http)->fetch('GBP', 'bad-key');
    }

    public function testFixerAsksForEuroBecauseThatIsAllTheFreeTierServes(): void
    {
        $http = FakeHttpClient::returningJson([
            'success' => true,
            'base' => 'EUR',
            'date' => '2026-09-11',
            'rates' => ['GBP' => 0.853, 'USD' => 1.0819],
        ]);

        $table = $this->fixer($http)->fetch('GBP', 'secret-key');

        self::assertSame('EUR', $table->baseCurrency);
        self::assertStringContainsString('base=EUR', $http->requestedUrls[0]);

        // And the service's rebasing step is what turns that into what the
        // instance actually asked for.
        $rebased = $table->rebasedTo('GBP');
        self::assertNotNull($rebased);
        self::assertSame('GBP', $rebased->baseCurrency);
        self::assertSame(ExchangeRate::SCALE, $rebased->rates['GBP']);
        // 1 GBP buys 1/0.853 EUR, about 1.1723.
        self::assertEqualsWithDelta(
            ExchangeRate::scaleFromDecimalString('1.17233'),
            $rebased->rates['EUR'],
            1000,
        );
    }

    public function testATransportFailureBecomesARecoverableProviderException(): void
    {
        $this->expectException(RateProviderException::class);
        $this->expectExceptionMessage('could not be reached');

        $this->frankfurter(FakeHttpClient::failing())->fetch('GBP', null);
    }

    public function testAnErrorStatusBecomesARecoverableProviderException(): void
    {
        $this->expectException(RateProviderException::class);
        $this->expectExceptionMessage('HTTP 503');

        $this->frankfurter(FakeHttpClient::returning('', 503))->fetch('GBP', null);
    }

    public function testAnUnparseableBodyBecomesARecoverableProviderException(): void
    {
        $this->expectException(RateProviderException::class);

        $this->frankfurter(FakeHttpClient::returning('<html>maintenance</html>'))->fetch('GBP', null);
    }

    public function testARateOfNullIsDroppedRatherThanTreatedAsZero(): void
    {
        // Feeds publish a null for a currency they have no data for that day.
        // Keeping the entry would convert every amount in it to nothing.
        $http = FakeHttpClient::returningJson([
            'base' => 'GBP',
            'rates' => ['EUR' => 1.1723, 'VES' => null, 'ZWL' => 0],
        ]);

        $table = $this->frankfurter($http)->fetch('GBP', null);

        self::assertArrayHasKey('EUR', $table->rates);
        self::assertArrayNotHasKey('VES', $table->rates);
        self::assertArrayNotHasKey('ZWL', $table->rates);
    }

    public function testAnEmptyRateSetIsRejectedRatherThanCachedAsEmpty(): void
    {
        $this->expectException(RateProviderException::class);

        $this->frankfurter(FakeHttpClient::returningJson(['base' => 'GBP', 'rates' => []]))->fetch('GBP', null);
    }

    public function testTheDefaultProviderIsTheFreeKeylessOne(): void
    {
        // Stated as a test because it is a decision, not an accident: a new
        // instance must convert currencies without its operator signing up to
        // anything, and Fixer must never become the default by reordering.
        $registry = new ExchangeRateProviderRegistry([
            $this->frankfurter(FakeHttpClient::returning('{}')),
            $this->exchangeRateHost(FakeHttpClient::returning('{}')),
            $this->fixer(FakeHttpClient::returning('{}')),
        ]);

        self::assertSame(FrankfurterProvider::KEY, $registry->default()->key());
        self::assertFalse($registry->default()->requiresApiKey());

        // An unknown or removed provider falls back to the default rather than
        // leaving the instance with no provider at all.
        self::assertSame(FrankfurterProvider::KEY, $registry->resolve('a-provider-that-was-removed')->key());
        self::assertSame(FixerProvider::KEY, $registry->resolve(FixerProvider::KEY)->key());
    }

    private function frankfurter(FakeHttpClient $http): FrankfurterProvider
    {
        return new FrankfurterProvider($http, new RequestFactory());
    }

    private function exchangeRateHost(FakeHttpClient $http): ExchangeRateHostProvider
    {
        return new ExchangeRateHostProvider($http, new RequestFactory());
    }

    private function fixer(FakeHttpClient $http): FixerProvider
    {
        return new FixerProvider($http, new RequestFactory());
    }
}
