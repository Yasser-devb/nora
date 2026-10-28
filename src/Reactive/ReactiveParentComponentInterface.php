<?php

declare(strict_types=1);

namespace Nora\Reactive;

interface ReactiveParentComponentInterface
{
    /**
     * Child definition order determines rendered order. Keys must be unique
     * within this parent render and stable across state changes.
     *
     * @param array<string, mixed> $state
     * @return list<ReactiveChildDefinition>
     */
    public function children(array $state): array;
}
