<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class RadioGroup
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
            'hint' => ['type' => 'string'],
            'required' => ['type' => 'boolean', 'default' => false],
            'disabled' => ['type' => 'boolean', 'default' => false],
        ], $props);
        $name = $resolved->get('name');
        if ($name === '' || $resolved->get('label') === '' || $options === []) {
            throw new InvalidArgumentException('A radio group requires a name, label, and options.');
        }
        $value = $resolved->has('value') ? $resolved->get('value') : null;
        $controls = [];
        $optionValues = [];
        foreach ($options as $index => $option) {
            if (
                !isset($option['value'], $option['label'])
                || !is_string($option['value'])
                || !is_string($option['label'])
                || $option['value'] === ''
                || $option['label'] === ''
                || (isset($option['disabled']) && !is_bool($option['disabled']))
                || isset($optionValues[$option['value']])
            ) {
                throw new InvalidArgumentException('Radio options require unique non-empty string values and labels.');
            }
            $optionValues[$option['value']] = true;
            $id = $name . '-' . $index;
            $controls[] = UI::element('div', ['class' => 'nora-radio'], [
                UI::element('input', [
                    'type' => 'radio',
                    'id' => $id,
                    'name' => $name,
                    'value' => $option['value'],
                    'checked' => $value === $option['value'],
                    'required' => $resolved->get('required'),
                    'disabled' => $resolved->get('disabled') || ($option['disabled'] ?? false),
                    'data-nora-field' => $name,
                ]),
                UI::element('label', ['for' => $id], $option['label']),
            ]);
        }
        if ($value !== null && !isset($optionValues[$value])) {
            throw new InvalidArgumentException('The selected value must match a declared radio option.');
        }
        $hintId = 'nora-radio-hint-' . substr(hash('sha256', $name), 0, 12);

        return UI::element('fieldset', [
            'class' => 'nora-radio-group',
            'aria-describedby' => $resolved->has('hint') ? $hintId : null,
        ], [
            UI::element('legend', [], $resolved->get('label')),
            ...$controls,
            $resolved->has('hint')
                ? UI::element('small', ['id' => $hintId], $resolved->get('hint'))
                : UI::fragment([]),
        ]);
    }
}
