<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class Avatar
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props): SafeHtml
    {
        $resolved = Props::resolve([
            'label' => ['type' => 'string', 'required' => true],
            'src' => ['type' => 'string'],
            'initials' => ['type' => 'string'],
            'size' => ['type' => 'string', 'default' => 'medium', 'one_of' => ['small', 'medium', 'large']],
        ], $props);
        $label = $resolved->get('label');
        if ($label === '') {
            throw new InvalidArgumentException('An avatar requires accessible alternative text.');
        }
        $fallback = $resolved->has('initials') ? $resolved->get('initials') : self::initials($label);

        return UI::element('span', [
            'class' => 'nora-avatar nora-avatar--' . $resolved->get('size'),
            'role' => 'img',
            'aria-label' => $label,
        ], [
            $resolved->has('src')
                ? UI::element('img', [
                    'src' => $resolved->get('src'),
                    'alt' => '',
                    'data-nora-avatar-image' => true,
                ])
                : UI::fragment([]),
            UI::element('span', [
                'class' => 'nora-avatar__fallback',
                'aria-hidden' => true,
                'hidden' => $resolved->has('src'),
                'data-nora-avatar-fallback' => true,
            ], $fallback),
        ]);
    }

    private static function initials(string $label): string
    {
        $words = preg_split('/\s+/u', trim($label), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            return '?';
        }
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= function_exists('mb_substr') ? mb_substr($word, 0, 1) : substr($word, 0, 1);
        }

        return $initials === '' ? '?' : (function_exists('mb_strtoupper') ? mb_strtoupper($initials) : strtoupper($initials));
    }
}
