<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Spinner
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props = []): SafeHtml
    {
        $resolved = Props::resolve([
            'size' => ['type' => 'string', 'default' => 'medium', 'one_of' => ['small', 'medium', 'large']],
            'label' => ['type' => 'string', 'default' => 'Loading'],
            'announce' => ['type' => 'boolean', 'default' => true],
        ], $props);
        $label = $resolved->get('label');
        $announce = $resolved->get('announce');

        return UI::element('span', [
            'class' => 'nora-spinner nora-spinner--' . $resolved->get('size'),
            'role' => $announce ? 'status' : null,
            'aria-label' => $announce ? $label : null,
            'aria-hidden' => $announce ? null : 'true',
        ], $announce ? $label : UI::fragment([]));
    }
}
