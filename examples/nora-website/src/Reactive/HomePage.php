<?php

declare(strict_types=1);

namespace NoraWebsite\Reactive;

use Nora\Reactive\ReactiveAction;
use Nora\Reactive\ReactiveComponent;
use Nora\View\TemplateRenderer;

final class HomePage implements ReactiveComponent
{
    public function __construct(private readonly TemplateRenderer $templates)
    {
    }

    public function initialState(): array
    {
        return ['count' => 0];
    }

    public function render(array $state): string
    {
        return '<link rel="stylesheet" href="/assets/site.css">'
            . $this->templates->render('shared/reactive-header')
            . '<div id="main-content">' . $this->templates->render('pages/home', $state) . '</div>'
            . $this->templates->render('shared/reactive-footer');
    }

    public function actions(): array
    {
        return [
            'home-increment' => ReactiveAction::publicAction(
                [],
                static fn (array $state): array => [...$state, 'count' => $state['count'] + 1],
            ),
            'home-reset' => ReactiveAction::publicAction(
                [],
                static fn (array $state): array => [...$state, 'count' => 0],
            ),
        ];
    }
}
