<?php

declare(strict_types=1);

namespace Nora\Validation;

interface ValidationRuleInterface
{
    public function validate(string $field, mixed $value): ?string;
}
