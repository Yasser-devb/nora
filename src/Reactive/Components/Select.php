<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Select
{
    /**
     * @param array<string, mixed> $props
     * @param list<array{value: string, label: string, disabled?: bool}> $options
     */
    public static function render(array $props, array $options): SafeHtml
    {
        $resolved = Props::resolve([
            'name' => ['type' => 'string', 'required' => true],
            'label' => ['type' => 'string', 'required' => true],
            'value' => ['type' => 'string'],
            'placeholder' => ['type' => 'string', 'default' => 'Choose an option'],
            'required' => ['type' => 'boolean', 'default' => false],
            'disabled' => ['type' => 'boolean', 'default' => false],
        ], $props);
        $name = $resolved->get('name');
        $label = $resolved->get('label');
        if ($name === '' || $label === '') {
            throw new InvalidArgumentException('A select requires a name and accessible label.');
        }
        $selected = $resolved->has('value') ? $resolved->get('value') : null;
        $nodes = [UI::element('option', [
            'value' => '',
            'disabled' => $resolved->get('required'),
            'selected' => $selected === null || $selected === '',
        ], $resolved->get('placeholder'))];
        $optionValues = [];
        foreach ($options as $option) {
            if (
                !isset($option['value'], $option['label'])
                || !is_string($option['value'])
                || !is_string($option['label'])
                || $option['value'] === ''
                || $option['label'] === ''
                || (isset($option['disabled']) && !is_bool($option['disabled']))
                || isset($optionValues[$option['value']])
            ) {
                throw new InvalidArgumentException('Select options require unique non-empty string values and labels.');
            }
            $optionValues[$option['value']] = true;
            $nodes[] = UI::element('option', [
                'value' => $option['value'],
                'selected' => $selected === $option['value'],
                'disabled' => $option['disabled'] ?? false,
            ], $option['label']);
        }
        if ($selected !== null && $selected !== '' && !isset($optionValues[$selected])) {
            throw new InvalidArgumentException('The selected value must match a declared select option.');
        }

        return UI::element('div', ['class' => 'nora-select'], [
            UI::element('label', ['for' => $name], $label),
            UI::element('select', [
                'id' => $name,
                'name' => $name,
                'data-nora-field' => $name,
                'required' => $resolved->get('required'),
                'disabled' => $resolved->get('disabled'),
            ], $nodes),
        ]);
    }
}
