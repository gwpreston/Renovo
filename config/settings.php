<?php

/**
 * Every setting the application has, read from the environment.
 *
 * Nothing is hardcoded that an operator might need to change, and no secret
 * has a default: an unset SESSION_KEY is a startup failure rather than a
 * silently insecure instance.
 */

declare(strict_types=1);

/**
 * @return string
 */
$env = static function (string $key, ?string $default = null): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === '') {
        if ($default === null) {
            throw new RuntimeException(sprintf(
                'Required environment variable %s is not set. Copy .env.example to .env and fill it in.',
                $key,
            ));
        }

        return $default;
    }

    return (string) $value;
};

$bool = static fn (string $value): bool => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);

$appEnv = $env('APP_ENV', 'production');

return [
    'app' => [
        'env' => $appEnv,
        'debug' => $bool($env('APP_DEBUG', $appEnv === 'production' ? 'false' : 'true')),
        'name' => $env('APP_NAME', 'Renovo'),
        'url' => rtrim($env('APP_URL', 'http://localhost:8080'), '/'),
        'locale' => $env('APP_LOCALE', 'en_GB'),
    ],

    'database' => [
        'driver' => $env('DB_DRIVER', 'pgsql'),
        'host' => $env('DB_HOST', 'database'),
        'port' => (int) $env('DB_PORT', $env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
        'name' => $env('DB_NAME', 'renovo'),
        'user' => $env('DB_USER', 'renovo'),
        'password' => $env('DB_PASSWORD', ''),
        'charset' => $env('DB_CHARSET', 'utf8'),
    ],

    'session' => [
        // No default: an instance running on a predictable session key is not
        // meaningfully protected at all.
        'key' => $env('SESSION_KEY'),
        'name' => $env('SESSION_NAME', 'renovo_session'),
        'lifetime' => (int) $env('SESSION_LIFETIME_SECONDS', '1209600'),
        'secure' => $bool($env('SESSION_COOKIE_SECURE', 'true')),
        'samesite' => $env('SESSION_COOKIE_SAMESITE', 'Lax'),
        'path' => '/',
        'domain' => $env('SESSION_COOKIE_DOMAIN', ''),
    ],

    'mail' => [
        'host' => $env('SMTP_HOST', 'localhost'),
        'port' => (int) $env('SMTP_PORT', '1025'),
        'user' => $env('SMTP_USER', ''),
        'password' => $env('SMTP_PASSWORD', ''),
        'encryption' => $env('SMTP_ENCRYPTION', 'none'),
        'from_address' => $env('MAIL_FROM_ADDRESS', 'renovo@localhost'),
        'from_name' => $env('MAIL_FROM_NAME', 'Renovo'),
    ],

    'http' => [
        'timeout' => (int) $env('HTTP_TIMEOUT_SECONDS', '10'),
        'connect_timeout' => (int) $env('HTTP_CONNECT_TIMEOUT_SECONDS', '5'),
        'max_response_bytes' => (int) $env('HTTP_MAX_RESPONSE_BYTES', '2097152'),
        'max_redirects' => (int) $env('HTTP_MAX_REDIRECTS', '3'),
        'user_agent' => $env('HTTP_USER_AGENT', 'Renovo'),
        'proxy' => $env('HTTP_PROXY', ''),
        'https_proxy' => $env('HTTPS_PROXY', ''),
        'no_proxy' => array_values(array_filter(array_map('trim', explode(',', $env('NO_PROXY', ''))))),
    ],

    'auth' => [
        'max_attempts_per_account' => (int) $env('AUTH_MAX_ATTEMPTS_PER_ACCOUNT', '5'),
        'max_attempts_per_ip' => (int) $env('AUTH_MAX_ATTEMPTS_PER_IP', '20'),
        'throttle_window' => (int) $env('AUTH_THROTTLE_WINDOW_SECONDS', '900'),
        'lockout_seconds' => (int) $env('AUTH_LOCKOUT_SECONDS', '900'),
    ],

    'uploads' => [
        'logo_directory' => dirname(__DIR__) . '/public/assets/logos',
        'logo_max_bytes' => (int) $env('UPLOAD_MAX_LOGO_BYTES', '1048576'),
    ],

    'paths' => [
        'root' => dirname(__DIR__),
        'templates' => dirname(__DIR__) . '/templates',
        'cache' => dirname(__DIR__) . '/var/cache',
        'logs' => dirname(__DIR__) . '/var/log',
    ],
];
