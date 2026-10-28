<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Badge
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, string|SafeHtml $label, ?SafeHtml $icon = null): SafeHtml
    {
        $resolved = Props::resolve([
            'variant' => ['type' => 'string', 'default' => 'neutral', 'one_of' => [
                'neutral', 'primary', 'success', 'warning', 'danger', 'info',
            ]],
            'dot' => ['type' => 'boolean', 'default' => false],
        ], $props);

        return UI::element('span', [
            'class' => 'nora-badge nora-badge--' . $resolved->get('variant'),
        ], [
            $icon,
            UI::when($resolved->get('dot'), UI::element('span', [
                'class' => 'nora-badge__dot',
                'aria-hidden' => true,
            ])),
            $label,
        ]);
    }
}
