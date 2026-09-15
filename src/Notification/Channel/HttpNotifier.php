<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Http\GuardedClient;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Shared mechanics for the channels that post JSON to a URL somebody typed.
 *
 * Note the type of `$http`: the guarded client, not `ClientInterface`. That is
 * not a stylistic preference — binding to the interface would let the container
 * hand one of these the plain client, and the plain client does not vet
 * destinations. A user-supplied URL reaching an unguarded client is the exact
 * failure this phase exists to prevent, so the dependency names the class that
 * cannot be substituted for one.
 */
abstract class HttpNotifier
{
    public function __construct(
        protected readonly GuardedClient $http,
        protected readonly RequestFactoryInterface $requests,
        protected readonly StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @throws NotifierException
     */
    protected function postJson(
        string $url,
        array $payload,
        array $headers = [],
        bool $httpsOnly = false,
    ): ResponseInterface {
        $body = $this->encode($payload);

        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($body));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            return $this->http->send($request, $httpsOnly);
        } catch (ClientExceptionInterface $exception) {
            // Covers both a refused destination and a dead one. The user sees
            // the reason against the channel; neither case is worth taking the
            // rest of the run down for.
            throw NotifierException::transport($this->label(), $exception->getMessage());
        }
    }

    /**
     * The one place a payload becomes bytes.
     *
     * Shared rather than inlined because a channel that signs its body must
     * sign the bytes that are actually sent. Two encodings with drifting flags
     * would produce a signature the receiver rejects, and the symptom — every
     * notification silently discarded at the far end — would point nowhere near
     * the cause.
     *
     * @param array<string, mixed> $payload
     * @throws NotifierException
     */
    protected function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw NotifierException::rejected($this->label(), 'the message could not be encoded');
        }
    }

    /**
     * Validate a URL the user typed, before it ever reaches the guard.
     *
     * This is a shape check, not a security one — the guard is what decides
     * where a request may go, and it does so at send time, when the answer is
     * current. Checking here as well means an obvious typo is reported next to
     * the field rather than as a failed notification a week later.
     *
     * @throws ValidationException
     */
    protected function validateUrl(string $field, string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw ValidationException::field($field, 'Enter a URL.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::field($field, 'Enter a valid URL, including https://.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::field($field, 'The URL must start with https:// or http://.');
        }

        if ((string) parse_url($url, PHP_URL_HOST) === '') {
            throw ValidationException::field($field, 'The URL is missing a host name.');
        }

        return rtrim($url, '/');
    }

    abstract public function label(): string;
}
