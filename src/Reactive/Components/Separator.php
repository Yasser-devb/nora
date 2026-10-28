<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Separator
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props = []): SafeHtml
    {
        $resolved = Props::resolve([
            'orientation' => ['type' => 'string', 'default' => 'horizontal', 'one_of' => ['horizontal', 'vertical']],
            'decorative' => ['type' => 'boolean', 'default' => true],
        ], $props);
        $orientation = $resolved->get('orientation');
        $decorative = $resolved->get('decorative');

        return UI::element('div', [
            'class' => 'nora-separator nora-separator--' . $orientation,
            'role' => $decorative ? null : 'separator',
            'aria-orientation' => $decorative || $orientation === 'horizontal' ? null : 'vertical',
            'aria-hidden' => $decorative ? 'true' : null,
        ]);
    }
}
