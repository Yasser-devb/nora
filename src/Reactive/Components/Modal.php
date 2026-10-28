<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Modal
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props, SafeHtml $content): SafeHtml
    {
        $resolved = Props::resolve([
            'id' => ['type' => 'string', 'required' => true],
            'title' => ['type' => 'string', 'required' => true],
            'trigger' => ['type' => 'string', 'default' => 'Open dialog'],
            'close_label' => ['type' => 'string', 'default' => 'Close dialog'],
            'description' => ['type' => 'string'],
            'open' => ['type' => 'boolean', 'default' => false],
            'close_action' => ['type' => 'string'],
        ], $props);
        $id = $resolved->get('id');
        if (
            preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1
            || $resolved->get('title') === ''
            || $resolved->get('trigger') === ''
            || $resolved->get('close_label') === ''
        ) {
            throw new InvalidArgumentException('A modal requires a valid id and non-empty accessible labels.');
        }
        $titleId = $id . '-title';
        $descriptionId = $id . '-description';

        return UI::fragment([
            UI::element('button', [
                'type' => 'button',
                'class' => 'nora-button nora-button--secondary',
                'data-nora-modal-open' => $id,
            ], $resolved->get('trigger')),
            UI::element('dialog', [
                'id' => $id,
                'class' => 'nora-modal',
                'aria-labelledby' => $titleId,
                'aria-describedby' => $resolved->has('description') ? $descriptionId : null,
                'data-nora-modal-state' => $resolved->get('open') ? 'open' : 'closed',
            ], [
                UI::element('header', ['class' => 'nora-modal__header'], [
                    UI::element('h2', ['id' => $titleId], $resolved->get('title')),
                    UI::element('button', [
                        'type' => 'button',
                        'class' => 'nora-button nora-button--outline',
                        'data-nora-modal-close' => true,
                        'aria-label' => $resolved->get('close_label'),
                    ], '×'),
                ]),
                $resolved->has('description')
                    ? UI::element('p', ['id' => $descriptionId, 'class' => 'nora-modal__description'], $resolved->get('description'))
                    : UI::fragment([]),
                UI::element('div', ['class' => 'nora-modal__body'], $content),
                $resolved->has('close_action')
                    ? UI::actionButton($resolved->get('close_action'), $resolved->get('close_label'), [], [
                        'hidden' => true,
                        'data-nora-modal-close-action' => true,
                    ])
                    : UI::fragment([]),
            ]),
        ]);
    }
}
