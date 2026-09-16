<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One field's validation failure, as a translation key rather than a sentence.
 *
 * Services raise these, and two different renderers turn them into text: a
 * Twig template through `error_message()`, and the API's error envelope
 * through the error handler. Keeping the key unresolved until then is what
 * lets both of them answer in the reader's own language without the service
 * knowing there is more than one language, or more than one renderer.
 */
final class ValidationError
{
    /**
     * @param array<string, string|int|float> $parameters
     */
    public function __construct(
        public readonly string $key,
        public readonly array $parameters = [],
    ) {
    }
}
