<?php

/**
 * Container wiring.
 *
 * Most classes are autowired; the definitions below are the ones that need a
 * decision — a concrete implementation for an interface, or a constructor
 * argument that comes from configuration rather than from another service.
 */

declare(strict_types=1);

use App\Application\Twig\AppExtension;
use App\Http\HttpClient;
use App\Http\HttpClientOptions;
use App\Persistence\Database;
use App\Persistence\PdoSessionHandler;
use App\Persistence\Platform;
use App\Persistence\PlatformFactory;
use App\Repository\AuthAttemptRepository;
use App\Repository\ExchangeRateRepository;
use App\Security\CsrfTokenManager;
use App\Security\Session;
use App\Security\SessionInterface;
use App\Service\AuthService;
use App\Service\ExchangeRate\ExchangeRateHostProvider;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FixerProvider;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRateService;
use App\Service\InstanceSettingsService;
use App\Service\LogoStorage;
use App\Service\SetupService;
use App\Service\MailerService;
use App\Service\PasswordResetService;
use App\Service\RateLimiter;
use App\Support\Clock;
use App\Support\MoneyFormatter;
use App\Support\SystemClock;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Views\Twig;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

use function DI\autowire;
use function DI\factory;
use function DI\get;

return static function (ContainerBuilder $builder, array $settings): void {
    $builder->addDefinitions([
        'settings' => $settings,

        // ------------------------------------------------------------------
        // Persistence
        // ------------------------------------------------------------------
        Platform::class => static fn (ContainerInterface $c): Platform => PlatformFactory::create(
            $c->get('settings')['database']['driver'],
        ),

        Database::class => static fn (ContainerInterface $c): Database => new Database(
            $c->get('settings')['database'],
            $c->get(Platform::class),
        ),

        PdoSessionHandler::class => static fn (ContainerInterface $c): PdoSessionHandler => new PdoSessionHandler(
            $c->get(Database::class),
            $c->get('settings')['session']['lifetime'],
        ),

        // ------------------------------------------------------------------
        // HTTP
        // ------------------------------------------------------------------
        RequestFactoryInterface::class => autowire(RequestFactory::class),
        ResponseFactoryInterface::class => autowire(ResponseFactory::class),
        StreamFactoryInterface::class => autowire(StreamFactory::class),

        HttpClientOptions::class => static function (ContainerInterface $c): HttpClientOptions {
            $http = $c->get('settings')['http'];

            return new HttpClientOptions(
                timeoutSeconds: $http['timeout'],
                connectTimeoutSeconds: $http['connect_timeout'],
                maxResponseBytes: $http['max_response_bytes'],
                maxRedirects: $http['max_redirects'],
                userAgent: $http['user_agent'],
                httpProxy: $http['proxy'] !== '' ? $http['proxy'] : null,
                httpsProxy: $http['https_proxy'] !== '' ? $http['https_proxy'] : null,
                noProxy: $http['no_proxy'],
            );
        },

        // Every outbound call in the application resolves to this one client.
        ClientInterface::class => autowire(HttpClient::class),

        // ------------------------------------------------------------------
        // Exchange rates
        // ------------------------------------------------------------------
        // Order matters: the first entry is the default for a new instance, and
        // it is the free, keyless one on purpose — currency conversion works
        // out of the box without the operator signing up to anything. Fixer is
        // offered but never first.
        ExchangeRateProviderRegistry::class => static fn (ContainerInterface $c): ExchangeRateProviderRegistry
            => new ExchangeRateProviderRegistry([
                $c->get(FrankfurterProvider::class),
                $c->get(ExchangeRateHostProvider::class),
                $c->get(FixerProvider::class),
            ]),

        ExchangeRateService::class => static function (ContainerInterface $c): ExchangeRateService {
            $rates = $c->get('settings')['rates'];

            return new ExchangeRateService(
                $c->get(ExchangeRateRepository::class),
                $c->get(ExchangeRateProviderRegistry::class),
                $c->get(InstanceSettingsService::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
                $rates['ttl_seconds'],
                $rates['retry_seconds'],
                $rates['api_key'],
            );
        },

        // ------------------------------------------------------------------
        // Session & security
        // ------------------------------------------------------------------
        SessionInterface::class => static function (ContainerInterface $c): SessionInterface {
            $session = $c->get('settings')['session'];

            return new Session([
                'name' => $session['name'],
                'lifetime' => $session['lifetime'],
                'secure' => $session['secure'],
                'samesite' => $session['samesite'],
                'path' => $session['path'],
                'domain' => $session['domain'],
            ]);
        },

        CsrfTokenManager::class => autowire()->constructorParameter('session', get(SessionInterface::class)),

        // Everything in the application reads "now" through this one clock,
        // in UTC, so stored timestamps do not depend on the server's locale.
        Clock::class => static fn (): Clock => new SystemClock(new DateTimeZone('UTC')),

        // ------------------------------------------------------------------
        // Services needing configuration values
        // ------------------------------------------------------------------
        RateLimiter::class => static function (ContainerInterface $c): RateLimiter {
            $auth = $c->get('settings')['auth'];

            return new RateLimiter(
                $c->get(AuthAttemptRepository::class),
                $c->get(Clock::class),
                $auth['max_attempts_per_account'],
                $auth['max_attempts_per_ip'],
                $auth['throttle_window'],
                $auth['lockout_seconds'],
            );
        },

        MailerInterface::class => static function (ContainerInterface $c): MailerInterface {
            $mail = $c->get('settings')['mail'];

            $scheme = match ($mail['encryption']) {
                'tls', 'ssl' => 'smtps',
                default => 'smtp',
            };

            $credentials = $mail['user'] !== ''
                ? rawurlencode($mail['user']) . ':' . rawurlencode($mail['password']) . '@'
                : '';

            $dsn = sprintf('%s://%s%s:%d', $scheme, $credentials, $mail['host'], $mail['port']);
            if ($scheme === 'smtp') {
                // A local relay such as Mailpit does not offer STARTTLS.
                $dsn .= '?verify_peer=0';
            }

            return new Mailer(Transport::fromDsn($dsn));
        },

        MailerService::class => static function (ContainerInterface $c): MailerService {
            $mail = $c->get('settings')['mail'];

            return new MailerService(
                $c->get(MailerInterface::class),
                $c->get(LoggerInterface::class),
                $mail['from_address'],
                $mail['from_name'],
            );
        },

        AuthService::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        PasswordResetService::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        // The wizard needs to know whether an environment key is already
        // present, so that it does not demand one the operator has supplied.
        SetupService::class => autowire()->constructorParameter(
            'environmentApiKey',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['rates']['api_key']),
        ),

        LogoStorage::class => static function (ContainerInterface $c): LogoStorage {
            $uploads = $c->get('settings')['uploads'];

            return new LogoStorage($uploads['logo_directory'], $uploads['logo_max_bytes']);
        },

        MoneyFormatter::class => static fn (ContainerInterface $c): MoneyFormatter => new MoneyFormatter(
            $c->get('settings')['app']['locale'],
        ),

        // ------------------------------------------------------------------
        // Logging & views
        // ------------------------------------------------------------------
        LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
            $settings = $c->get('settings');
            $logger = new Logger($settings['app']['name']);

            $directory = $settings['paths']['logs'];
            if (!is_dir($directory)) {
                @mkdir($directory, 0o775, true);
            }

            // stderr in containers, a file when that is not writable.
            $target = is_writable($directory) ? $directory . '/app.log' : 'php://stderr';
            $logger->pushHandler(new StreamHandler($target, $settings['app']['debug'] ? Level::Debug : Level::Info));

            return $logger;
        },

        Twig::class => static function (ContainerInterface $c): Twig {
            $settings = $c->get('settings');

            $twig = Twig::create($settings['paths']['templates'], [
                'cache' => $settings['app']['debug'] ? false : $settings['paths']['cache'] . '/twig',
                'debug' => $settings['app']['debug'],
                'strict_variables' => false,
                // Every template output is escaped unless a value is
                // explicitly marked safe.
                'autoescape' => 'html',
            ]);

            $twig->addExtension($c->get(AppExtension::class));

            return $twig;
        },
    ]);
};
