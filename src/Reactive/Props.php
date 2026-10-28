<?php

declare(strict_types=1);

namespace Nora\Reactive;

use InvalidArgumentException;

final readonly class Props
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private array $values)
    {
    }

    /**
     * Definitions use type, optional required/default, and optional one_of keys.
     *
     * @param array<string, array{type: string, required?: bool, default?: mixed, one_of?: list<mixed>}> $definitions
     * @param array<string, mixed> $values
     */
    public static function resolve(array $definitions, array $values): self
    {
        foreach (array_keys($definitions) as $name) {
            if (!is_string($name) || preg_match('/^[a-z][a-zA-Z0-9_]*$/D', $name) !== 1) {
                throw new InvalidArgumentException('A component prop definition has an invalid name.');
            }
            if (
                !is_array($definitions[$name])
                || !is_string($definitions[$name]['type'] ?? null)
                || !in_array($definitions[$name]['type'], [
                    'string', 'integer', 'float', 'number', 'boolean', 'array', 'safe_html',
                ], true)
            ) {
                throw new InvalidArgumentException('A component prop must declare a supported type.');
            }
            $definition = $definitions[$name];
            if (
                (array_key_exists('required', $definition) && !is_bool($definition['required']))
                || (array_key_exists('one_of', $definition)
                    && (!is_array($definition['one_of']) || !array_is_list($definition['one_of'])))
                || (($definition['required'] ?? false) === true && array_key_exists('default', $definition))
            ) {
                throw new InvalidArgumentException('A component prop definition is inconsistent.');
            }
            if (isset($definition['one_of'])) {
                foreach ($definition['one_of'] as $allowed) {
                    if (!is_scalar($allowed) && $allowed !== null) {
                        throw new InvalidArgumentException('Component prop enum values must be scalar or null.');
                    }
                }
            }
        }
        foreach (array_keys($values) as $name) {
            if (!is_string($name) || !array_key_exists($name, $definitions)) {
                throw new InvalidArgumentException('An undeclared component prop was provided.');
            }
        }

        $resolved = [];
        foreach ($definitions as $name => $definition) {
            if (array_key_exists($name, $values)) {
                $value = $values[$name];
            } elseif (array_key_exists('default', $definition)) {
                $value = $definition['default'];
            } elseif (($definition['required'] ?? false) === true) {
                throw new InvalidArgumentException('Required component prop "' . $name . '" is missing.');
            } else {
                continue;
            }

            if (!self::matchesType($definition['type'], $value)) {
                throw new InvalidArgumentException(
                    'Component prop "' . $name . '" must have type ' . $definition['type'] . '.',
                );
            }
            if (isset($definition['one_of']) && !in_array($value, $definition['one_of'], true)) {
                throw new InvalidArgumentException('Component prop "' . $name . '" has an unsupported value.');
            }
            $resolved[$name] = $value;
        }

        return new self($resolved);
    }

    public function get(string $name): mixed
    {
        if (!array_key_exists($name, $this->values)) {
            throw new InvalidArgumentException('Component prop "' . $name . '" is not defined.');
        }

        return $this->values[$name];
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    private static function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'float' => is_float($value) && is_finite($value),
            'number' => (is_int($value) || is_float($value)) && (!is_float($value) || is_finite($value)),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'safe_html' => $value instanceof SafeHtml,
            default => throw new InvalidArgumentException('Unsupported component prop type "' . $type . '".'),
        };
    }
}
