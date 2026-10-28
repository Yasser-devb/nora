<?php

declare(strict_types=1);

namespace Nora\Reactive;

use InvalidArgumentException;

final readonly class Slots
{
    /**
     * @param array<string, SafeHtml> $slots
     */
    public function __construct(private array $slots = [])
    {
        foreach ($slots as $name => $content) {
            if (
                !is_string($name)
                || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1
                || !$content instanceof SafeHtml
            ) {
                throw new InvalidArgumentException('Slots require safe markup and valid slot names.');
            }
        }
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->slots);
    }

    public function get(string $name, ?SafeHtml $fallback = null): SafeHtml
    {
        if (array_key_exists($name, $this->slots)) {
            return $this->slots[$name];
        }
        if ($fallback !== null) {
            return $fallback;
        }

        return UI::fragment([]);
    }

    public function require(string $name): SafeHtml
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException('Required component slot "' . $name . '" is missing.');
        }

        return $this->slots[$name];
    }
}
