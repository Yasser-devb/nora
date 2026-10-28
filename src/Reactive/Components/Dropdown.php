<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Dropdown
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, SafeHtml $items): SafeHtml
    {
        $resolved = Props::resolve([
            'label' => ['type' => 'string', 'required' => true],
        ], $props);
        if ($resolved->get('label') === '') {
            throw new \InvalidArgumentException('A dropdown requires an accessible label.');
        }

        return UI::element('details', [
            'class' => 'nora-dropdown',
            'data-nora-dropdown' => true,
        ], [
            UI::element('summary', [
                'class' => 'nora-dropdown__trigger',
            ], $resolved->get('label')),
            UI::element('div', [
                'class' => 'nora-dropdown__menu',
                'role' => 'group',
                'aria-label' => $resolved->get('label'),
            ], $items),
        ]);
    }
}
