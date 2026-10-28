<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Accordion
{
    /**
     * @param list<array{id: string, title: string, content: SafeHtml, open?: bool}> $items
     */
    public static function render(string $label, array $items, bool $multiple = true): SafeHtml
    {
        if ($label === '' || $items === []) {
            throw new InvalidArgumentException('An accordion requires a label and at least one item.');
        }
        $ids = [];
        $nodes = [];
        foreach ($items as $item) {
            if (
                !isset($item['id'], $item['title'], $item['content'])
                || !is_string($item['id'])
                || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $item['id']) !== 1
                || !is_string($item['title'])
                || $item['title'] === ''
                || !$item['content'] instanceof SafeHtml
                || (isset($item['open']) && !is_bool($item['open']))
                || isset($ids[$item['id']])
            ) {
                throw new InvalidArgumentException('Accordion items require unique ids, titles, and safe content.');
            }
            $ids[$item['id']] = true;
            $nodes[] = UI::element('details', [
                'id' => $item['id'],
                'class' => 'nora-accordion__item',
                'name' => $multiple ? null : $label,
                'open' => $item['open'] ?? false,
            ], [
                UI::element('summary', [], $item['title']),
                UI::element('div', ['class' => 'nora-accordion__content'], $item['content']),
            ]);
        }

        return UI::element('div', [
            'class' => 'nora-accordion',
            'role' => 'group',
            'aria-label' => $label,
            'data-nora-accordion-mode' => $multiple ? 'multiple' : 'single',
        ], $nodes);
    }
}
