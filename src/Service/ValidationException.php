<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Field-level validation failures, raised by services so that web controllers
 * and API endpoints can render the same errors in their own formats without
 * duplicating the rules.
 *
 * Each failure is a translation key and its arguments, not a finished
 * sentence. A message written here would be in whatever language this file
 * happens to be written in, whoever is reading it.
 */
final class ValidationException extends RuntimeException
{
    /** @var array<string, ValidationError> */
    private readonly array $errors;

    /**
     * @param array<string, ValidationError|string> $errors Field name => error,
     *        or the bare translation key where it takes no arguments.
     */
    public function __construct(array $errors)
    {
        $normalised = [];
        foreach ($errors as $field => $error) {
            $normalised[$field] = is_string($error) ? new ValidationError($error) : $error;
        }

        $this->errors = $normalised;

        // Not shown to anybody: the renderers read errors() instead. It exists
        // so that a log line or a stack trace says what kind of exception this
        // was rather than nothing at all.
        parent::__construct('The submitted data is not valid.');
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    public static function field(string $field, string $key, array $parameters = []): self
    {
        return new self([$field => new ValidationError($key, $parameters)]);
    }

    /**
     * @return array<string, ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The keys alone, for the rare caller that needs to know which fields
     * failed rather than what to say about them.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return array_keys($this->errors);
    }
}
