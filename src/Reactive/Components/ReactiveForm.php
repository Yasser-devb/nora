<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class ReactiveForm
{
    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $attributes
     */
    public static function render(array $props, SafeHtml $children, array $attributes = []): SafeHtml
    {
        $resolved = Props::resolve([
            'action_name' => ['type' => 'string', 'required' => true],
            'action_url' => ['type' => 'string', 'default' => '#'],
            'success_message' => ['type' => 'string'],
        ], $props);
        $actionName = $resolved->get('action_name');
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $actionName) !== 1) {
            throw new InvalidArgumentException('The reactive form action name is invalid.');
        }
        foreach (array_keys($attributes) as $name) {
            if (
                !is_string($name)
                || in_array(strtolower($name), [
                    'method', 'action', 'data-nora-form', 'data-nora-action', 'data-nora-success',
                ], true)
            ) {
                throw new InvalidArgumentException('Form attributes cannot override reactive form protocol fields.');
            }
        }

        return UI::element('form', [
            ...$attributes,
            'method' => 'post',
            'action' => $resolved->get('action_url'),
            'data-nora-form' => true,
            'data-nora-action' => $actionName,
            'data-nora-success' => $resolved->has('success_message')
                ? $resolved->get('success_message')
                : null,
        ], $children);
    }
}
