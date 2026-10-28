<?php

declare(strict_types=1);

namespace Nora\Reactive;

use LogicException;

abstract class Component implements ReactiveComponent
{
    /**
     * @param array<string, mixed> $state
     */
    final public function render(array $state): string
    {
        $markup = $this->view(new State($state));
        if (!$markup instanceof SafeHtml) {
            throw new LogicException('A component view must return safe markup built by Nora UI.');
        }

        return $markup->toHtml();
    }

    /**
     * @return SafeHtml
     */
    abstract protected function view(State $state): SafeHtml;
}
