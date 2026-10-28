<?php

declare(strict_types=1);

namespace Nora\Reactive;

use InvalidArgumentException;

final class UI
{
    public static function text(string|int|float|bool|null $value): SafeHtml
    {
        return SafeHtml::text($value);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function element(string $tag, array $attributes = [], mixed $children = null): SafeHtml
    {
        return SafeHtml::element($tag, $attributes, $children);
    }

    /**
     * @param iterable<mixed> $children
     */
    public static function fragment(iterable $children): SafeHtml
    {
        return SafeHtml::fragment($children);
    }

    public static function when(bool $condition, mixed $content): SafeHtml
    {
        return SafeHtml::fragment($condition ? [$content] : []);
    }

    /**
     * @param iterable<mixed> $items
     */
    public static function each(
        iterable $items,
        callable $key,
        callable $render,
    ): SafeHtml {
        $children = [];
        $seen = [];
        foreach ($items as $item) {
            $itemKey = $key($item);
            if (!is_string($itemKey) && !is_int($itemKey)) {
                throw new InvalidArgumentException('Collection keys must be strings or integers.');
            }
            $identity = (string) $itemKey;
            if (
                $identity === ''
                || strlen($identity) > 128
                || preg_match('/[\x00-\x1f\x7f]/', $identity) === 1
                || isset($seen[$identity])
            ) {
                throw new InvalidArgumentException('Collection keys must be unique.');
            }
            $seen[$identity] = true;

            $element = $render($item, $itemKey);
            if (!$element instanceof SafeHtml) {
                throw new InvalidArgumentException('Collection render callbacks must return safe HTML.');
            }
            $children[] = $element->withKey($itemKey);
        }

        return SafeHtml::fragment($children);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $attributes
     */
    public static function actionButton(
        string $action,
        string|int|float|SafeHtml $label,
        array $arguments = [],
        array $attributes = [],
    ): SafeHtml {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $action) !== 1) {
            throw new InvalidArgumentException('A reactive action name is invalid.');
        }
        foreach (array_keys($arguments) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Reactive action arguments must use string keys.');
            }
        }
        foreach (array_keys($attributes) as $name) {
            if (
                !is_string($name)
                || in_array(strtolower($name), ['type', 'data-nora-action', 'data-nora-args'], true)
            ) {
                throw new InvalidArgumentException('Action buttons cannot override protocol attributes.');
            }
        }

        try {
            $encodedArguments = json_encode(
                (object) $arguments,
                JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
            );
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Reactive action arguments are not JSON-safe.', 0, $exception);
        }

        return SafeHtml::element(
            'button',
            [
                ...$attributes,
                'type' => 'button',
                'data-nora-action' => $action,
                'data-nora-args' => $encodedArguments,
            ],
            $label,
        );
    }
}
