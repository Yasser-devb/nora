<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Table
{
    /**
     * @param array<string, mixed> $props
     * @param list<string|SafeHtml> $headers
     * @param iterable<mixed> $rows
     */
    public static function render(
        array $props,
        array $headers,
        iterable $rows,
        callable $key,
        callable $cells,
    ): SafeHtml {
        $resolved = Props::resolve([
            'caption' => ['type' => 'string'],
            'empty_message' => ['type' => 'string', 'default' => 'No results.'],
        ], $props);
        if ($headers === []) {
            throw new InvalidArgumentException('A table requires at least one column header.');
        }
        foreach ($headers as $header) {
            if (!is_string($header) && !$header instanceof SafeHtml) {
                throw new InvalidArgumentException('Table headers must be strings or safe markup.');
            }
        }

        $bodyRows = [];
        $hasRows = false;
        foreach ($rows as $row) {
            $hasRows = true;
            $rowKey = $key($row);
            $rowCells = $cells($row);
            if (!is_array($rowCells) || count($rowCells) !== count($headers)) {
                throw new InvalidArgumentException('Each table row must provide one cell per header.');
            }
            foreach ($rowCells as $cell) {
                if (
                    !is_string($cell)
                    && !is_int($cell)
                    && !is_float($cell)
                    && !$cell instanceof SafeHtml
                ) {
                    throw new InvalidArgumentException('Table cells must be scalar text or safe markup.');
                }
            }
            $bodyRows[] = ['key' => $rowKey, 'cells' => $rowCells];
        }

        $body = $hasRows
            ? UI::each(
                $bodyRows,
                static fn (array $row): string|int => $row['key'],
                static fn (array $row): SafeHtml => UI::element(
                    'tr',
                    [],
                    array_map(
                        static fn (string|int|float|SafeHtml $cell): SafeHtml => UI::element('td', [], $cell),
                        $row['cells'],
                    ),
                ),
            )
            : UI::element('tr', [], UI::element('td', [
                'colspan' => count($headers),
                'class' => 'nora-table__empty',
            ], $resolved->get('empty_message')));
        $caption = $resolved->has('caption')
            ? UI::element('caption', [], $resolved->get('caption'))
            : UI::fragment([]);

        return UI::element('div', ['class' => 'nora-table-scroll'], UI::element('table', [
            'class' => 'nora-table',
        ], [
            $caption,
            UI::element('thead', [], UI::element('tr', [], array_map(
                static fn (string|SafeHtml $header): SafeHtml => UI::element('th', ['scope' => 'col'], $header),
                $headers,
            ))),
            UI::element('tbody', [], $body),
        ]));
    }
}
