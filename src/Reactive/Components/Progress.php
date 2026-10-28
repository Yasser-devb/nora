<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Progress
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props): SafeHtml
    {
        $resolved = Props::resolve([
            'value' => ['type' => 'number'],
            'max' => ['type' => 'number', 'default' => 100],
            'label' => ['type' => 'string', 'required' => true],
        ], $props);
        $max = $resolved->get('max');
        $value = $resolved->has('value') ? $resolved->get('value') : null;
        if ($max <= 0 || $resolved->get('label') === '' || ($value !== null && ($value < 0 || $value > $max))) {
            throw new InvalidArgumentException('Progress requires a label and valid value/max range.');
        }

        return UI::element('progress', [
            'class' => 'nora-progress',
            'aria-label' => $resolved->get('label'),
            'max' => $max,
            'value' => $value,
        ]);
    }
}
