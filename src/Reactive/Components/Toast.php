<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Toast
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, string|SafeHtml $message): SafeHtml
    {
        $resolved = Props::resolve([
            'id' => ['type' => 'string', 'required' => true],
            'variant' => ['type' => 'string', 'default' => 'info', 'one_of' => ['success', 'error', 'warning', 'info']],
            'dismissible' => ['type' => 'boolean', 'default' => true],
            'label' => ['type' => 'string', 'default' => 'Notification'],
            'dismiss_action' => ['type' => 'string'],
        ], $props);
        $id = $resolved->get('id');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1) {
            throw new InvalidArgumentException('A toast requires a valid unique id.');
        }
        $variant = $resolved->get('variant');

        return UI::element('div', [
            'id' => $id,
            'class' => 'nora-toast nora-toast--' . $variant,
            'role' => $variant === 'error' || $variant === 'warning' ? 'alert' : 'status',
            'aria-label' => $resolved->get('label'),
            'aria-live' => $variant === 'error' || $variant === 'warning' ? 'assertive' : 'polite',
            'data-nora-toast' => true,
        ], [
            $message,
            UI::when($resolved->get('dismissible'), $resolved->has('dismiss_action')
                ? UI::actionButton($resolved->get('dismiss_action'), '×', [], [
                    'class' => 'nora-toast__dismiss',
                    'data-nora-toast-dismiss' => true,
                    'aria-label' => 'Dismiss notification',
                ])
                : UI::element('button', [
                    'type' => 'button',
                    'class' => 'nora-toast__dismiss',
                    'data-nora-toast-dismiss' => true,
                    'aria-label' => 'Dismiss notification',
                ], '×')),
        ]);
    }
}
