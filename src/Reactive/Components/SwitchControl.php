<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class SwitchControl
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props): SafeHtml
    {
        $resolved = Props::resolve([
            'name' => ['type' => 'string', 'required' => true],
            'label' => ['type' => 'string', 'required' => true],
            'checked' => ['type' => 'boolean', 'default' => false],
            'disabled' => ['type' => 'boolean', 'default' => false],
        ], $props);
        $name = $resolved->get('name');
        if ($name === '' || $resolved->get('label') === '') {
            throw new InvalidArgumentException('A switch requires a name and accessible label.');
        }

        return UI::element('div', ['class' => 'nora-switch'], [
            UI::element('input', [
                'type' => 'checkbox',
                'role' => 'switch',
                'id' => $name,
                'name' => $name,
                'value' => '1',
                'checked' => $resolved->get('checked'),
                'disabled' => $resolved->get('disabled'),
                'data-nora-field' => $name,
            ]),
            UI::element('label', ['for' => $name], $resolved->get('label')),
        ]);
    }
}
