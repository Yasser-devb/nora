<?php

declare(strict_types=1);

namespace Nora\Reactive;

final readonly class State
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $path): bool
    {
        $sentinel = new \stdClass();

        return $this->get($path, $sentinel) !== $sentinel;
    }

    /**
     * Compute a derived value from this immutable render snapshot.
     */
    public function computed(callable $compute): mixed
    {
        return $compute($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
