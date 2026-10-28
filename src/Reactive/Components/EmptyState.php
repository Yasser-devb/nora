<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\Slots;
use Nora\Reactive\UI;

final class EmptyState
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, Slots $slots): SafeHtml
    {
        $resolved = Props::resolve([
            'title' => ['type' => 'string', 'required' => true],
            'description' => ['type' => 'string'],
        ], $props);
        if ($resolved->get('title') === '') {
            throw new InvalidArgumentException('An empty state requires a title.');
        }

        return UI::element('section', [
            'class' => 'nora-empty-state',
            'aria-label' => $resolved->get('title'),
        ], [
            $slots->get('icon'),
            UI::element('h2', [], $resolved->get('title')),
            $resolved->has('description')
                ? UI::element('p', [], $resolved->get('description'))
                : UI::fragment([]),
            $slots->get('action'),
        ]);
    }
}
