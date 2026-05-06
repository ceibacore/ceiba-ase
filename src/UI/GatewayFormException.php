<?php

declare(strict_types=1);

namespace LemurAse\UI;

/**
 * Thrown by GatewayFormManager::processSubmission() on CSRF failure
 * or field validation errors.
 *
 * Check getErrors() for field-keyed validation messages.
 */
final class GatewayFormException extends \RuntimeException
{
    /** @param array<string, string> $errors  field_name => error message */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
