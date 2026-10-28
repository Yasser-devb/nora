<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Breadcrumb
{
    /**
     * @param list<array{label: string, href?: string}> $items
     */
    public static function render(string $label, array $items): SafeHtml
    {
        if ($label === '' || $items === []) {
            throw new InvalidArgumentException('Breadcrumbs require a label and at least one item.');
        }
        $last = array_key_last($items);
        $nodes = [];
        foreach ($items as $index => $item) {
            if (!isset($item['label']) || !is_string($item['label']) || $item['label'] === '') {
                throw new InvalidArgumentException('Each breadcrumb requires a non-empty label.');
            }
            if ($index !== $last && (!isset($item['href']) || !is_string($item['href']) || $item['href'] === '')) {
                throw new InvalidArgumentException('Every non-current breadcrumb requires a URL.');
            }
            $content = $index === $last
                ? UI::element('span', ['aria-current' => 'page'], $item['label'])
                : UI::element('a', ['href' => $item['href']], $item['label']);
            $nodes[] = UI::element('li', [], $content);
        }

        return UI::element('nav', ['aria-label' => $label, 'class' => 'nora-breadcrumb'], UI::element(
            'ol',
            [],
            $nodes,
        ));
    }
}
