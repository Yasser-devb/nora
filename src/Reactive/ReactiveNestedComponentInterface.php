<?php

declare(strict_types=1);

namespace Nora\Reactive;

interface ReactiveNestedComponentInterface
{
    /**
     * @return array<string, string|list<string|\Nora\Validation\ValidationRuleInterface>>
     */
    public function propsRules(): array;

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public function initialState(array $props): array;

    /**
     * Render trusted markup; escape all dynamic values.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $props
     */
    public function render(array $state, array $props): string;

    /**
     * @return array<string, ReactiveAction>
     */
    public function actions(): array;

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $props
     * @return list<ReactiveChildDefinition>
     */
    public function children(array $state, array $props): array;
}
