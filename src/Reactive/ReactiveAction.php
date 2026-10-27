<?php

declare(strict_types=1);

namespace Nora\Reactive;

use Closure;
use Nora\Validation\ValidationResult;
use Nora\Validation\ValidationRuleInterface;
use Nora\Validation\Validator;

final class ReactiveAction
{
    private readonly Closure $transition;

    private readonly ?Closure $authorization;

    /**
     * @param array<string, string|list<string|ValidationRuleInterface>> $argumentRules
     */
    public function __construct(
        private readonly array $argumentRules,
        callable $transition,
        ?callable $authorization = null,
    ) {
        $this->transition = Closure::fromCallable($transition);
        $this->authorization = $authorization === null ? null : Closure::fromCallable($authorization);
    }

    /**
     * Declare an action as intentionally available to every valid page holder.
     *
     * @param array<string, string|list<string|ValidationRuleInterface>> $argumentRules
     */
    public static function publicAction(array $argumentRules, callable $transition): self
    {
        return new self($argumentRules, $transition, static fn (): bool => true);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return ValidationResult
     */
    public function validateArguments(array $arguments): ValidationResult
    {
        foreach (array_keys($arguments) as $key) {
            if (!is_string($key) || !array_key_exists($key, $this->argumentRules)) {
                return new ValidationResult(['arguments' => ['The action contains an unknown argument.']]);
            }
        }

        return (new Validator())->validate($arguments, $this->argumentRules);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $arguments
     */
    public function isAuthorized(array $state, array $arguments): bool
    {
        return $this->authorization !== null && ($this->authorization)($state, $arguments) === true;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function transition(array $state, array $arguments): array
    {
        $nextState = ($this->transition)($state, $arguments);
        if (!is_array($nextState)) {
            throw new \UnexpectedValueException('A reactive action must return an array state.');
        }

        return $nextState;
    }
}
