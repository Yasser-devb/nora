<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Tooltip
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, SafeHtml $trigger): SafeHtml
    {
        $resolved = Props::resolve([
            'id' => ['type' => 'string', 'required' => true],
            'content' => ['type' => 'string', 'required' => true],
        ], $props);
        $id = $resolved->get('id');
        $content = $resolved->get('content');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1 || $content === '') {
            throw new InvalidArgumentException('A tooltip requires a valid id and non-empty text.');
        }

        return UI::element('span', ['class' => 'nora-tooltip'], [
            $trigger->withAttributes(['aria-describedby' => $id]),
            UI::element('span', [
                'class' => 'nora-tooltip__content',
                'id' => $id,
                'role' => 'tooltip',
            ], $content),
        ]);
    }
}
