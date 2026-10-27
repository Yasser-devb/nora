<?php

declare(strict_types=1);

namespace Nora\Validation;

use InvalidArgumentException;

final class Validator
{
    private const RULES = [
        'required',
        'string',
        'numeric',
        'integer',
        'boolean',
        'email',
        'min',
        'max',
    ];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string|ValidationRuleInterface>> $rules
     */
    public function validate(array $data, array $rules): ValidationResult
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $parsedRules = is_string($fieldRules)
                ? (explode('|', $fieldRules) === [''] ? [] : explode('|', $fieldRules))
                : $fieldRules;
            [$present, $value] = $this->valueAtPath($data, $field);
            $required = in_array('required', $parsedRules, true);

            if (!$present || $value === null || (is_string($value) && trim($value) === '')) {
                if ($required) {
                    $errors[$field][] = 'The field is required.';
                }
                continue;
            }

            $numericMode = in_array('numeric', $parsedRules, true)
                || in_array('integer', $parsedRules, true);

            foreach ($parsedRules as $rule) {
                if ($rule === 'required') {
                    continue;
                }

                if ($rule instanceof ValidationRuleInterface) {
                    $message = $rule->validate($field, $value);
                    if ($message !== null) {
                        $errors[$field][] = $message;
                    }
                    continue;
                }

                [$name, $argument] = $this->parseRule($rule);
                $message = $this->validateRule($name, $argument, $field, $value, $numericMode);
                if ($message !== null) {
                    $errors[$field][] = $message;
                }
            }
        }

        return new ValidationResult($errors);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{bool, mixed}
     */
    private function valueAtPath(array $data, string $path): array
    {
        if ($path === '') {
            throw new InvalidArgumentException('A validation field path cannot be empty.');
        }

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }

    /**
     * @return array{string, string|null}
     */
    private function parseRule(string $rule): array
    {
        [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
        if (!in_array($name, self::RULES, true)) {
            throw new InvalidArgumentException('Unsupported validation rule "' . $name . '".');
        }
        if (in_array($name, ['min', 'max'], true)) {
            if ($argument === null || !is_numeric($argument)) {
                throw new InvalidArgumentException('Validation rule "' . $name . '" requires a numeric limit.');
            }
        } elseif ($argument !== null) {
            throw new InvalidArgumentException('Validation rule "' . $name . '" does not accept an argument.');
        }

        return [$name, $argument];
    }

    private function validateRule(
        string $rule,
        ?string $argument,
        string $field,
        mixed $value,
        bool $numericMode,
    ): ?string
    {
        $valid = match ($rule) {
            'string' => is_string($value),
            'numeric' => is_numeric($value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1'], true),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'min' => $this->compareLimit($value, (float) $argument, 'min', $numericMode),
            'max' => $this->compareLimit($value, (float) $argument, 'max', $numericMode),
            default => false,
        };

        return $valid ? null : 'The field failed the ' . $rule . ' validation rule.';
    }

    private function compareLimit(mixed $value, float $limit, string $comparison, bool $numericMode): bool
    {
        if ($numericMode && is_numeric($value)) {
            $measure = (float) $value;
        } elseif (is_string($value)) {
            $measure = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        } elseif (is_numeric($value)) {
            $measure = (float) $value;
        } else {
            return false;
        }

        return $comparison === 'min' ? $measure >= $limit : $measure <= $limit;
    }
}
