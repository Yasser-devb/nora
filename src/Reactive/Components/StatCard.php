<?php

declare(strict_types=1);

namespace Nora\Reactive\Components;

use InvalidArgumentException;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\UI;

final class StatCard
{
    /**
     * @param array<string, mixed> $props
     */
    public static function render(array $props): SafeHtml
    {
        $resolved = Props::resolve([
            'label' => ['type' => 'string', 'required' => true],
            'value' => ['type' => 'string', 'required' => true],
            'description' => ['type' => 'string'],
            'trend' => ['type' => 'string', 'one_of' => ['up', 'down', 'neutral']],
            'trend_label' => ['type' => 'string'],
        ], $props);
        if ($resolved->get('label') === '') {
            throw new InvalidArgumentException('A statistic card requires a label.');
        }

        return UI::element('article', ['class' => 'nora-stat-card'], [
            UI::element('h3', ['class' => 'nora-stat-card__label'], $resolved->get('label')),
            UI::element('p', ['class' => 'nora-stat-card__value'], $resolved->get('value')),
            $resolved->has('description')
                ? UI::element('p', ['class' => 'nora-stat-card__description'], $resolved->get('description'))
                : UI::fragment([]),
            $resolved->has('trend') && $resolved->has('trend_label')
                ? UI::element('span', [
                    'class' => 'nora-stat-card__trend nora-stat-card__trend--' . $resolved->get('trend'),
                ], $resolved->get('trend_label'))
                : UI::fragment([]),
        ]);
    }
}
