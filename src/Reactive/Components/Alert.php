<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\Slots;
use Nora\Reactive\UI;

final class Alert
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, Slots $slots): SafeHtml
    {
        $resolved = Props::resolve([
            'variant' => ['type' => 'string', 'default' => 'info', 'one_of' => ['info', 'success', 'warning', 'danger', 'error']],
            'label' => ['type' => 'string', 'default' => 'Notice'],
            'dismissible' => ['type' => 'boolean', 'default' => false],
            'dismiss_label' => ['type' => 'string', 'default' => 'Dismiss alert'],
        ], $props);

        return UI::element('div', [
            'class' => 'nora-alert nora-alert--' . ($resolved->get('variant') === 'error' ? 'danger' : $resolved->get('variant')),
            'role' => in_array($resolved->get('variant'), ['warning', 'danger', 'error'], true) ? 'alert' : 'status',
            'aria-label' => $resolved->get('label'),
        ], [
            UI::when($slots->has('title'), UI::element('strong', ['class' => 'nora-alert__title'], $slots->get('title'))),
            $slots->get('icon'),
            $slots->get('default'),
            UI::when($resolved->get('dismissible'), UI::element('button', [
                'type' => 'button',
                'class' => 'nora-alert__dismiss',
                'data-nora-dismiss' => true,
                'aria-label' => $resolved->get('dismiss_label'),
            ], '×')),
        ]);
    }
}
