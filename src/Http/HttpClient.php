<?php

declare(strict_types=1);

namespace App\Http;

use CurlHandle;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The one way this application talks to anything outside itself.
 *
 * Nothing else may call curl, file_get_contents or a vendored HTTP client
 * directly. Centralising it means a limit added here — a timeout, a size cap,
 * later a check on where a URL actually resolves to — applies everywhere at
 * once, including to code written long after it.
 *
 * This phase enforces: http/https only, connect and total timeouts, a maximum
 * response size checked while the body streams in (not after), a redirect cap
 * with the same scheme restriction applied to each hop, and proxy settings.
 *
 * Validation of user-supplied destinations — rejecting private and
 * link-local addresses, pinning the resolved IP against DNS rebinding — is the
 * concern of the phase that first accepts a URL from a user. Nothing in this
 * phase passes one in.
 */
final class HttpClient implements ClientInterface
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly HttpClientOptions $options,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw HttpClientException::unsupportedScheme($scheme);
        }

        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw HttpClientException::transport($request, 'could not initialise curl');
        }

        $responseHeaders = [];
        $body = '';
        $received = 0;
        $limit = $this->options->maxResponseBytes;
        $exceeded = false;

        $requestBody = (string) $request->getBody();

        curl_setopt_array($handle, [
            CURLOPT_URL => (string) $uri,
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $this->flattenHeaders($request),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => $this->options->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->options->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => $this->options->maxRedirects > 0,
            CURLOPT_MAXREDIRS => $this->options->maxRedirects,
            // A redirect may not escape into another protocol.
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_USERAGENT => $this->options->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return strlen($line);
                }
                if (str_starts_with($trimmed, 'HTTP/')) {
                    // A new status line means a redirect hop; drop what we had.
                    $responseHeaders = [];

                    return strlen($line);
                }
                $parts = explode(':', $trimmed, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])][] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (
                $ch,
                string $chunk
            ) use (
                &$body,
                &$received,
                &$exceeded,
                $limit
            ): int {
                $received += strlen($chunk);
                if ($received > $limit) {
                    $exceeded = true;

                    // Returning a short count aborts the transfer, so an
                    // oversized body is never fully downloaded.
                    return -1;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        if ($requestBody !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $requestBody);
        }

        $proxy = $this->options->proxyFor($scheme, $uri->getHost());
        if ($proxy !== null) {
            curl_setopt($handle, CURLOPT_PROXY, $proxy);
        }

        $succeeded = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated
        // in 8.5. The handle is freed when it goes out of scope.

        if ($exceeded) {
            throw HttpClientException::responseTooLarge($request, $limit);
        }

        if ($succeeded === false && $errorNumber !== 0) {
            throw HttpClientException::transport($request, $errorMessage);
        }

        $response = $this->responseFactory
            ->createResponse($status === 0 ? 500 : $status)
            ->withBody($this->streamFactory->createStream($body));

        foreach ($responseHeaders as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private function flattenHeaders(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        return $headers;
    }
}
