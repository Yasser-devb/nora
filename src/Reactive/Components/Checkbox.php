<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Checkbox
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props): SafeHtml
    {
        $resolved = Props::resolve([
            'name' => ['type' => 'string', 'required' => true],
            'label' => ['type' => 'string', 'required' => true],
            'hint' => ['type' => 'string'],
            'checked' => ['type' => 'boolean', 'default' => false],
            'required' => ['type' => 'boolean', 'default' => false],
            'disabled' => ['type' => 'boolean', 'default' => false],
        ], $props);
        $name = $resolved->get('name');
        if ($name === '' || $resolved->get('label') === '') {
            throw new InvalidArgumentException('A checkbox requires a name and accessible label.');
        }
        $hintId = 'nora-checkbox-hint-' . substr(hash('sha256', $name), 0, 12);

        return UI::element('div', ['class' => 'nora-checkbox'], [
            UI::element('input', [
                'type' => 'checkbox',
                'id' => $name,
                'name' => $name,
                'value' => '1',
                'checked' => $resolved->get('checked'),
                'required' => $resolved->get('required'),
                'disabled' => $resolved->get('disabled'),
                'data-nora-field' => $name,
                'aria-describedby' => $resolved->has('hint') ? $hintId : null,
            ]),
            UI::element('label', ['for' => $name], $resolved->get('label')),
            $resolved->has('hint')
                ? UI::element('small', ['id' => $hintId], $resolved->get('hint'))
                : UI::fragment([]),
        ]);
    }
}
