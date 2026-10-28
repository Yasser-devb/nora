<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Skeleton
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props = []): SafeHtml
    {
        $resolved = Props::resolve([
            'variant' => ['type' => 'string', 'default' => 'text', 'one_of' => ['text', 'rectangle', 'avatar', 'card']],
            'width' => ['type' => 'string', 'default' => 'full', 'one_of' => ['full', 'three-quarter', 'half', 'quarter']],
            'height' => ['type' => 'string', 'default' => 'medium', 'one_of' => ['small', 'medium', 'large']],
        ], $props);
        $variant = $resolved->get('variant');

        return UI::element('span', [
            'class' => 'nora-skeleton nora-skeleton--' . $variant
                . ' nora-skeleton--width-' . $resolved->get('width')
                . ' nora-skeleton--height-' . $resolved->get('height'),
            'aria-hidden' => true,
        ]);
    }
}
