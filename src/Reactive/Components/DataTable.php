<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class DataTable
{
    /**
     * @param array<string, mixed> $props
     * @param iterable<array<string, mixed>> $rows
     */
    public static function render(
        array $props,
        iterable $rows,
        callable $key,
        callable $cells,
    ): SafeHtml {
        $resolved = Props::resolve([
            'columns' => ['type' => 'array', 'required' => true],
            'caption' => ['type' => 'string', 'required' => true],
            'query' => ['type' => 'string', 'default' => ''],
            'sort' => ['type' => 'string'],
            'direction' => ['type' => 'string', 'default' => 'asc', 'one_of' => ['asc', 'desc']],
            'page' => ['type' => 'integer', 'default' => 1],
            'per_page' => ['type' => 'integer', 'default' => 5],
            'sort_action' => ['type' => 'string'],
            'page_action' => ['type' => 'string'],
            'action_arguments' => ['type' => 'array', 'default' => []],
            'empty_message' => ['type' => 'string', 'default' => 'No matching records.'],
        ], $props);
        $columns = $resolved->get('columns');
        if ($columns === [] || $resolved->get('caption') === '' || $resolved->get('page') < 1 || $resolved->get('per_page') < 1) {
            throw new InvalidArgumentException('A data table requires columns, a caption, and valid paging values.');
        }
        $columnKeys = [];
        foreach ($columns as $column) {
            if (
                !is_array($column)
                || !is_string($column['key'] ?? null)
                || !is_string($column['label'] ?? null)
                || $column['key'] === ''
                || $column['label'] === ''
                || (isset($column['sortable']) && !is_bool($column['sortable']))
                || isset($columnKeys[$column['key']])
            ) {
                throw new InvalidArgumentException('Data table columns require unique keys and labels.');
            }
            $columnKeys[$column['key']] = true;
        }
        $sort = $resolved->has('sort') ? $resolved->get('sort') : null;
        if ($sort !== null && !isset($columnKeys[$sort])) {
            throw new InvalidArgumentException('The data table sort key must match a declared column.');
        }

        $filtered = [];
        $query = trim($resolved->get('query'));
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Data table rows must be associative arrays.');
            }
            $matches = true;
            if ($query !== '') {
                $matches = false;
                foreach (array_keys($columnKeys) as $columnKey) {
                    $value = $row[$columnKey] ?? null;
                    if ((is_string($value) || is_int($value) || is_float($value))
                        && stripos((string) $value, $query) !== false) {
                        $matches = true;
                        break;
                    }
                }
            }
            if ($matches) {
                $filtered[] = $row;
            }
        }
        $direction = $resolved->get('direction');
        if ($sort !== null) {
            usort($filtered, static function (array $left, array $right) use ($sort, $direction): int {
                $a = $left[$sort] ?? '';
                $b = $right[$sort] ?? '';
                if (!is_scalar($a) || !is_scalar($b)) {
                    throw new InvalidArgumentException('Data table sort values must be scalar.');
                }
                $comparison = is_numeric($a) && is_numeric($b)
                    ? (float) $a <=> (float) $b
                    : strcasecmp((string) $a, (string) $b);

                return $direction === 'desc' ? -$comparison : $comparison;
            });
        }
        $totalRows = count($filtered);
        $perPage = $resolved->get('per_page');
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($resolved->get('page'), $totalPages);
        $visible = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        $headers = [];
        $actionArguments = $resolved->get('action_arguments');
        foreach ($columns as $column) {
            $header = $column['label'];
            if ($resolved->has('sort_action') && ($column['sortable'] ?? true)) {
                $nextDirection = $sort === $column['key'] && $direction === 'asc' ? 'desc' : 'asc';
                $header = Button::render([
                    'variant' => 'ghost',
                    'size' => 'small',
                    'action' => $resolved->get('sort_action'),
                    'arguments' => [
                        ...$actionArguments,
                        'sort' => $column['key'],
                        'direction' => $nextDirection,
                        'query' => $query,
                    ],
                ], $column['label']);
            }
            $headers[] = UI::element('th', [
                'scope' => 'col',
                'aria-sort' => $sort === $column['key']
                    ? ($direction === 'asc' ? 'ascending' : 'descending')
                    : null,
            ], $header);
        }
        $table = Table::render(
            ['caption' => $resolved->get('caption'), 'empty_message' => $resolved->get('empty_message')],
            $headers,
            $visible,
            $key,
            $cells,
        );
        $pageControl = $resolved->has('page_action')
            ? Pagination::render([
                'current' => $page,
                'total' => $totalPages,
                'action' => $resolved->get('page_action'),
            ], [...$actionArguments, 'query' => $query])
            : UI::fragment([]);

        return UI::element('div', [
            'class' => 'nora-data-table',
            'data-nora-result-count' => $totalRows,
        ], [
            UI::element('p', [
                'class' => 'nora-data-table__summary',
                'aria-live' => 'polite',
            ], $totalRows . ' ' . ($totalRows === 1 ? 'result' : 'results')),
            $table,
            $pageControl,
        ]);
    }
}
