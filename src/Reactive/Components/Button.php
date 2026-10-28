<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\Slots;
use Nora\Reactive\UI;

final class Button
{
    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $attributes
     */
    public static function render(
        array $props,
        string|SafeHtml $label,
        array $attributes = [],
        ?Slots $slots = null,
    ): SafeHtml {
        $resolved = Props::resolve([
            'variant' => ['type' => 'string', 'default' => 'primary', 'one_of' => ['primary', 'secondary', 'outline', 'ghost', 'link', 'danger']],
            'size' => ['type' => 'string', 'default' => 'medium', 'one_of' => ['small', 'medium', 'large']],
            'disabled' => ['type' => 'boolean', 'default' => false],
            'loading' => ['type' => 'boolean', 'default' => false],
            'type' => ['type' => 'string', 'default' => 'button', 'one_of' => ['button', 'submit', 'reset']],
            'action' => ['type' => 'string'],
            'arguments' => ['type' => 'array', 'default' => []],
        ], $props);
        if ($label === '') {
            throw new InvalidArgumentException('A button requires a non-empty accessible label.');
        }
        foreach (array_keys($attributes) as $name) {
            if (
                !is_string($name)
                || in_array(strtolower($name), ['type', 'disabled', 'class', 'data-nora-action', 'data-nora-args'], true)
            ) {
                throw new InvalidArgumentException('Button attributes cannot override component or action properties.');
            }
        }

        $class = 'nora-button nora-button--' . $resolved->get('variant')
            . ' nora-button--' . $resolved->get('size');
        if ($resolved->get('loading')) {
            $class .= ' nora-button--loading';
        }
        $attributes = [
            ...$attributes,
            'type' => $resolved->get('type'),
            'class' => $class,
            'disabled' => $resolved->get('disabled') || $resolved->get('loading'),
            'aria-busy' => $resolved->get('loading') ? 'true' : null,
        ];
        $children = [];
        if ($slots?->has('leading-icon')) {
            $children[] = UI::element('span', [
                'class' => 'nora-button__icon nora-button__icon--leading',
                'aria-hidden' => true,
            ], $slots->get('leading-icon'));
        }
        array_push($children, ...($resolved->get('loading')
            ? [$label, UI::element('span', [
                'class' => 'nora-button__spinner',
                'aria-hidden' => true,
            ])]
            : [$label]));
        if ($slots?->has('trailing-icon')) {
            $children[] = UI::element('span', [
                'class' => 'nora-button__icon nora-button__icon--trailing',
                'aria-hidden' => true,
            ], $slots->get('trailing-icon'));
        }
        $content = UI::fragment($children);
        if ($resolved->has('action')) {
            if ($resolved->get('type') !== 'button') {
                throw new InvalidArgumentException('Reactive buttons must use type="button".');
            }
            unset($attributes['type']);
            return UI::actionButton(
                $resolved->get('action'),
                $content,
                $resolved->get('arguments'),
                $attributes,
            );
        }

        return UI::element('button', $attributes, $content);
    }
}
