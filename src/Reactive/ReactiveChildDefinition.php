<?php

declare(strict_types=1);

namespace Nora\Reactive;

use InvalidArgumentException;

final readonly class ReactiveChildDefinition
{
    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        private string $key,
        private string $component,
        private array $props = [],
    ) {
        if (
            preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $key) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $component) !== 1
        ) {
            throw new InvalidArgumentException('A reactive child key or component name is invalid.');
        }
    }

    public static function placeholder(string $key): string
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $key) !== 1) {
            throw new InvalidArgumentException('A reactive child key is invalid.');
        }

        return '<!--nora-child:' . $key . '-->';
    }

    public function key(): string
    {
        return $this->key;
    }

    public function component(): string
    {
        return $this->component;
    }

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return $this->props;
    }
}
