<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\Slots;
use Nora\Reactive\UI;

final class Card
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, Slots $slots): SafeHtml
    {
        $resolved = Props::resolve([
            'as' => ['type' => 'string', 'default' => 'section', 'one_of' => ['article', 'section', 'div']],
            'labelled_by' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ], $props);
        $hasHeader = $slots->has('header')
            || $resolved->has('title')
            || $resolved->has('description')
            || $slots->has('actions');
        $header = UI::fragment([]);
        if ($hasHeader) {
            $heading = $slots->has('header')
                ? $slots->get('header')
                : ($resolved->has('title')
                    ? UI::element('h2', [], $resolved->get('title'))
                    : UI::fragment([]));
            $headerChildren = [$heading];
            if ($resolved->has('description')) {
                $headerChildren[] = UI::element('p', ['class' => 'nora-card__description'], $resolved->get('description'));
            }
            if ($slots->has('actions')) {
                $headerChildren[] = UI::element('div', ['class' => 'nora-card__actions'], $slots->get('actions'));
            }
            $header = UI::element('header', ['class' => 'nora-card__header'], $headerChildren);
        }

        return UI::element($resolved->get('as'), [
            'class' => 'nora-card',
            'aria-labelledby' => $resolved->has('labelled_by') ? $resolved->get('labelled_by') : null,
        ], [
            $header,
            UI::element('div', ['class' => 'nora-card__body'], $slots->get('default')),
            UI::when($slots->has('footer'), UI::element('footer', ['class' => 'nora-card__footer'], $slots->get('footer'))),
        ]);
    }
}
