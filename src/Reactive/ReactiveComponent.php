<?php

declare(strict_types=1);

namespace Nora\Reactive;

interface ReactiveComponent
{
    /**
     * @return array<string, mixed>
     */
    public function initialState(): array;

    /**
     * Render trusted component markup. Escape all dynamic values.
     *
     * @param array<string, mixed> $state
     */
    public function render(array $state): string;

    /**
     * @return array<string, ReactiveAction>
     */
    public function actions(): array;
}
