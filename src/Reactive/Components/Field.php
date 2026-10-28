<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Field
{
    /**
     * @param array<string, mixed> $props
     * @param list<string> $errors
     */
    public static function render(array $props, SafeHtml $control, array $errors = []): SafeHtml
    {
        $resolved = Props::resolve([
            'name' => ['type' => 'string', 'required' => true],
            'label' => ['type' => 'string', 'required' => true],
            'required' => ['type' => 'boolean', 'default' => false],
            'hint' => ['type' => 'string'],
        ], $props);
        foreach ($errors as $error) {
            if (!is_string($error)) {
                throw new InvalidArgumentException('Field errors must be strings.');
            }
        }
        if (!$control->isFormControl()) {
            throw new InvalidArgumentException('A field must wrap a safe input, select, or textarea element.');
        }
        $name = $resolved->get('name');
        if ($name === '' || $resolved->get('label') === '') {
            throw new InvalidArgumentException('A field name and accessible label must not be empty.');
        }
        $hintId = 'nora-hint-' . self::identifier($name);
        $errorId = 'nora-error-' . self::identifier($name);
        $describedBy = [];
        if ($resolved->has('hint')) {
            $describedBy[] = $hintId;
        }
        if ($errors !== []) {
            $describedBy[] = $errorId;
        }
        $control = $control->withAttributes([
            'id' => $name,
            'name' => $name,
            'data-nora-field' => $name,
            'required' => $resolved->get('required'),
            'aria-invalid' => $errors === [] ? null : 'true',
            'aria-describedby' => $describedBy === [] ? null : implode(' ', $describedBy),
        ]);

        return UI::element('div', ['class' => 'nora-field'], [
            UI::element('label', ['class' => 'nora-field__label', 'for' => $name], [
                $resolved->get('label'),
                UI::when($resolved->get('required'), UI::element('span', [
                    'class' => 'nora-field__required',
                    'aria-label' => 'required',
                ], '*')),
            ]),
            $control,
            $resolved->has('hint')
                ? UI::element('small', [
                    'class' => 'nora-field__hint',
                    'id' => $hintId,
                ], $resolved->get('hint'))
                : UI::fragment([]),
            UI::when($errors !== [], UI::element('ul', [
                'class' => 'nora-field__errors',
                'id' => $errorId,
                'role' => 'alert',
            ], array_map(static fn (string $error): SafeHtml => UI::element('li', [], $error), $errors))),
        ]);
    }

    private static function identifier(string $value): string
    {
        return substr(hash('sha256', $value), 0, 12);
    }
}
