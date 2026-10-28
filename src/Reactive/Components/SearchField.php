<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class SearchField
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props): SafeHtml
    {
        $resolved = Props::resolve([
            'name' => ['type' => 'string', 'default' => 'query'],
            'label' => ['type' => 'string', 'default' => 'Search'],
            'value' => ['type' => 'string', 'default' => ''],
            'placeholder' => ['type' => 'string', 'default' => 'Search records'],
        ], $props);
        $name = $resolved->get('name');
        if ($name === '' || $resolved->get('label') === '') {
            throw new InvalidArgumentException('A search field requires a name and label.');
        }

        return UI::element('div', ['class' => 'nora-search-field'], [
            UI::element('label', ['for' => $name], $resolved->get('label')),
            UI::element('input', [
                'type' => 'search',
                'id' => $name,
                'name' => $name,
                'value' => $resolved->get('value'),
                'placeholder' => $resolved->get('placeholder'),
                'data-nora-field' => $name,
            ]),
            UI::element('button', [
                'type' => 'button',
                'data-nora-search-clear' => true,
                'aria-label' => 'Clear ' . $resolved->get('label'),
            ], 'Clear'),
        ]);
    }
}
