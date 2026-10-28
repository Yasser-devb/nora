<?php

declare(strict_types=1);

namespace Nora\Reactive;

interface ReactiveComponentLifecycleInterface
{
    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $state
     */
    public function mounted(string $identity, array $props, array $state): void;

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $state
     */
    public function disposed(string $identity, array $props, array $state): void;
}
