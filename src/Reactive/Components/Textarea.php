<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Textarea
{
    /**
     * @param array<string, mixed> $props
     * @param list<string> $errors
     */
    public static function render(array $props, array $errors = []): SafeHtml
    {
        $resolved = Props::resolve([
            'name' => ['type' => 'string', 'required' => true],
            'label' => ['type' => 'string', 'required' => true],
            'value' => ['type' => 'string', 'default' => ''],
            'hint' => ['type' => 'string'],
            'rows' => ['type' => 'integer', 'default' => 4],
            'required' => ['type' => 'boolean', 'default' => false],
            'disabled' => ['type' => 'boolean', 'default' => false],
        ], $props);
        if (
            $resolved->get('name') === ''
            || $resolved->get('label') === ''
            || $resolved->get('rows') < 1
            || $resolved->get('rows') > 30
        ) {
            throw new InvalidArgumentException('A textarea needs a name, label, and between 1 and 30 rows.');
        }
        $control = UI::element('textarea', [
            'rows' => $resolved->get('rows'),
            'disabled' => $resolved->get('disabled'),
        ], $resolved->get('value'));

        return Field::render([
            'name' => $resolved->get('name'),
            'label' => $resolved->get('label'),
            'required' => $resolved->get('required'),
            ...($resolved->has('hint') ? ['hint' => $resolved->get('hint')] : []),
        ], $control, $errors);
    }
}
