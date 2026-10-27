<?php

declare(strict_types=1);

namespace Nora\Validation;

final class ValidationResult
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(private readonly array $errors)
    {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }
}
