<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\Slots;
use Nora\Reactive\UI;

final class Layout
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, Slots $slots): SafeHtml
    {
        $resolved = Props::resolve([
            'title' => ['type' => 'string', 'required' => true],
            'content_label' => ['type' => 'string', 'default' => 'Page content'],
        ], $props);

        return UI::element('div', ['class' => 'nora-layout'], [
            UI::when($slots->has('navigation'), UI::element('nav', [
                'class' => 'nora-layout__navigation',
                'aria-label' => 'Main navigation',
            ], $slots->get('navigation'))),
            UI::element('header', ['class' => 'nora-layout__header'], [
                UI::element('h1', [], $resolved->get('title')),
                $slots->get('header'),
            ]),
            UI::element('section', [
                'class' => 'nora-layout__content',
                'aria-label' => $resolved->get('content_label'),
            ], $slots->get('default')),
            UI::when($slots->has('footer'), UI::element('footer', [
                'class' => 'nora-layout__footer',
            ], $slots->get('footer'))),
        ]);
    }
}
