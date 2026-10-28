<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Tabs
{
    /**
     * @param list<array{id: string, label: string, content: SafeHtml, disabled?: bool}> $tabs
     */
    public static function render(string $id, string $label, array $tabs, ?string $selected = null): SafeHtml
    {
        if (
            preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1
            || $label === ''
            || $tabs === []
        ) {
            throw new InvalidArgumentException('Tabs require a valid id and at least one tab.');
        }
        $ids = [];
        foreach ($tabs as $tab) {
            if (
                !isset($tab['id'], $tab['label'], $tab['content'])
                || !is_string($tab['id'])
                || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $tab['id']) !== 1
                || !is_string($tab['label'])
                || $tab['label'] === ''
                || !$tab['content'] instanceof SafeHtml
                || (isset($tab['disabled']) && !is_bool($tab['disabled']))
                || isset($ids[$tab['id']])
            ) {
                throw new InvalidArgumentException('Each tab needs a unique id, label, and safe panel content.');
            }
            $ids[$tab['id']] = true;
        }
        $selectableIds = array_column(array_filter(
            $tabs,
            static fn (array $tab): bool => !($tab['disabled'] ?? false),
        ), 'id');
        $selected ??= $selectableIds[0] ?? null;
        if ($selected === null || !isset($ids[$selected]) || !in_array($selected, $selectableIds, true)) {
            throw new InvalidArgumentException('The selected tab does not exist.');
        }

        return UI::element('section', [
            'class' => 'nora-tabs',
            'data-nora-tabs' => true,
        ], [
            UI::element('div', [
                'class' => 'nora-tabs__list',
                'role' => 'tablist',
                'aria-label' => $label,
            ], array_map(static function (array $tab) use ($id, $selected): SafeHtml {
                $active = $tab['id'] === $selected;

                return UI::element('button', [
                    'type' => 'button',
                    'class' => 'nora-tabs__tab',
                    'id' => $id . '-tab-' . $tab['id'],
                    'role' => 'tab',
                    'aria-controls' => $id . '-panel-' . $tab['id'],
                    'aria-selected' => $active ? 'true' : 'false',
                    'tabindex' => $active ? '0' : '-1',
                    'disabled' => $tab['disabled'] ?? false,
                    'data-nora-tab-target' => $tab['id'],
                ], $tab['label']);
            }, $tabs)),
            ...array_map(static function (array $tab) use ($id, $selected): SafeHtml {
                $active = $tab['id'] === $selected;

                return UI::element('div', [
                    'class' => 'nora-tabs__panel',
                    'id' => $id . '-panel-' . $tab['id'],
                    'role' => 'tabpanel',
                    'aria-labelledby' => $id . '-tab-' . $tab['id'],
                    'tabindex' => '0',
                    'data-nora-tab-panel' => $tab['id'],
                    'hidden' => !$active,
                ], $tab['content']);
            }, $tabs),
        ]);
    }
}
