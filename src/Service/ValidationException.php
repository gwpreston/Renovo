<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Field-level validation failures, raised by services so that web controllers
 * and (in a later phase) API endpoints can render the same errors in their own
 * formats without duplicating the rules.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors Field name => message.
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The submitted data is not valid.');
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
