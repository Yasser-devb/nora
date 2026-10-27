<?php

declare(strict_types=1);

namespace NoraWebsite\Reactive;

use Nora\Reactive\ReactiveAction;
use Nora\Reactive\ReactiveComponent;
use Nora\View\TemplateRenderer;

final class PlaygroundPage implements ReactiveComponent
{
    public function __construct(private readonly TemplateRenderer $templates)
    {
    }

    public function initialState(): array
    {
        return [
            'count' => 0,
            'active_tab' => 'counter',
            'counter_tab_class' => 'is-active',
            'validation_tab_class' => '',
            'counter_selected' => 'true',
            'validation_selected' => 'false',
            'counter_panel_hidden' => 'false',
            'validation_panel_hidden' => 'true',
        ];
    }

    public function render(array $state): string
    {
        return '<link rel="stylesheet" href="/assets/site.css">'
            . $this->templates->render('shared/reactive-header')
            . '<div id="main-content">' . $this->templates->render('pages/playground', $state) . '</div>'
            . $this->templates->render('shared/reactive-footer');
    }

    public function actions(): array
    {
        $counterRules = ['amount' => 'required|integer|min:1|max:5'];

        return [
            'increment' => ReactiveAction::publicAction(
                $counterRules,
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'count' => $state['count'] + (int) $arguments['amount'],
                ],
            ),
            'decrement' => ReactiveAction::publicAction(
                $counterRules,
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'count' => max(0, $state['count'] - (int) $arguments['amount']),
                ],
            ),
            'reset' => ReactiveAction::publicAction(
                [],
                static fn (array $state): array => [...$state, 'count' => 0],
            ),
            'select-tab' => new ReactiveAction(
                ['tab' => 'required|string|max:16'],
                static function (array $state, array $arguments): array {
                    $isCounter = $arguments['tab'] === 'counter';

                    return [
                        ...$state,
                        'active_tab' => $arguments['tab'],
                        'counter_tab_class' => $isCounter ? 'is-active' : '',
                        'validation_tab_class' => $isCounter ? '' : 'is-active',
                        'counter_selected' => $isCounter ? 'true' : 'false',
                        'validation_selected' => $isCounter ? 'false' : 'true',
                        'counter_panel_hidden' => $isCounter ? 'false' : 'true',
                        'validation_panel_hidden' => $isCounter ? 'true' : 'false',
                    ];
                },
                static fn (array $state, array $arguments): bool =>
                    in_array($arguments['tab'], ['counter', 'validation'], true),
            ),
            'protected-demo' => new ReactiveAction(
                [],
                static fn (array $state): array => [...$state, 'count' => $state['count'] + 100],
                static fn (): bool => false,
            ),
        ];
    }
}
