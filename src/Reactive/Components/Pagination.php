<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Pagination
{
    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $baseArguments
     */
    public static function render(array $props, array $baseArguments = []): SafeHtml
    {
        $resolved = Props::resolve([
            'current' => ['type' => 'integer', 'required' => true],
            'total' => ['type' => 'integer', 'required' => true],
            'action' => ['type' => 'string'],
            'page_argument' => ['type' => 'string', 'default' => 'page'],
            'label' => ['type' => 'string', 'default' => 'Pagination'],
        ], $props);
        $current = $resolved->get('current');
        $total = $resolved->get('total');
        $action = $resolved->has('action') ? $resolved->get('action') : null;
        if ($current < 1 || $total < 1 || $current > $total || $resolved->get('label') === '') {
            throw new InvalidArgumentException('Pagination requires a valid current page, total, and label.');
        }
        $pageArgument = $resolved->get('page_argument');
        if (preg_match('/^[a-z][a-zA-Z0-9_]*$/D', $pageArgument) !== 1) {
            throw new InvalidArgumentException('The pagination page argument name is invalid.');
        }
        $previous = self::control($action, $baseArguments, $pageArgument, $current - 1, 'Previous page', $current === 1);
        $next = self::control($action, $baseArguments, $pageArgument, $current + 1, 'Next page', $current === $total);

        return UI::element('nav', [
            'class' => 'nora-pagination',
            'aria-label' => $resolved->get('label'),
        ], [
            $previous,
            UI::element('span', [
                'class' => 'nora-pagination__status',
                'aria-live' => 'polite',
            ], 'Page ' . $current . ' of ' . $total),
            $next,
        ]);
    }

    /**
     * @param array<string, mixed> $baseArguments
     */
    private static function control(
        ?string $action,
        array $baseArguments,
        string $pageArgument,
        int $page,
        string $label,
        bool $disabled,
    ): SafeHtml {
        $arguments = [...$baseArguments, $pageArgument => $page];
        if ($action !== null) {
            return UI::actionButton($action, $label, $arguments, [
                'class' => 'nora-button nora-button--outline nora-button--small',
                'disabled' => $disabled,
                'aria-label' => $label,
            ]);
        }

        return UI::element($disabled ? 'span' : 'a', [
            'href' => $disabled ? null : '?page=' . $page,
            'aria-disabled' => $disabled ? 'true' : null,
            'tabindex' => $disabled ? '-1' : null,
            'class' => 'nora-pagination__link' . ($disabled ? ' is-disabled' : ''),
            'aria-label' => $label,
        ], $label);
    }
}
