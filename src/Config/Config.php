<?php

declare(strict_types=1);

namespace Nora\Config;

final class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    /**
     * Merge sources from lowest to highest precedence.
     *
     * @param array<string, mixed> ...$sources
     */
    public static function fromSources(array ...$sources): self
    {
        $merged = [];
        foreach ($sources as $source) {
            $merged = array_replace_recursive($merged, $source);
        }

        return new self($merged);
    }

    public function has(string $key): bool
    {
        return $this->lookup($key)[0];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->lookup($key);

        return $found ? $value : $default;
    }

    public function require(string $key): mixed
    {
        [$found, $value] = $this->lookup($key);
        if (!$found) {
            throw new ConfigException('Required configuration "' . $key . '" is missing.');
        }

        return $value;
    }

    /**
     * Validate required dot-path keys against supported PHP types.
     *
     * Supported types: string, non-empty-string, integer, boolean, float, array.
     *
     * @param array<string, string> $schema
     */
    public function validate(array $schema): void
    {
        foreach ($schema as $key => $type) {
            $value = $this->require($key);
            $valid = match ($type) {
                'string' => is_string($value),
                'non-empty-string' => is_string($value) && trim($value) !== '',
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'float' => is_float($value),
                'array' => is_array($value),
                default => throw new ConfigException('Unsupported configuration type "' . $type . '".'),
            };

            if (!$valid) {
                throw new ConfigException(
                    'Configuration "' . $key . '" must be of type "' . $type . '".',
                );
            }
        }
    }

    /**
     * @return array{bool, mixed}
     */
    private function lookup(string $key): array
    {
        if ($key === '') {
            return [false, null];
        }

        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }
}
