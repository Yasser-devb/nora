<?php

declare(strict_types=1);

namespace Nora\Security;

use Closure;
use InvalidArgumentException;

final class Gate
{
    /** @var array<string, Closure(AuthenticatableInterface, mixed): bool> */
    private array $abilities = [];

    /**
     * @param callable(AuthenticatableInterface, mixed): bool $callback
     */
    public function define(string $ability, callable $callback): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/D', $ability) !== 1) {
            throw new InvalidArgumentException('The authorization ability name is invalid.');
        }

        $this->abilities[$ability] = Closure::fromCallable($callback);
    }

    public function allows(
        AuthenticatableInterface $user,
        string $ability,
        mixed $resource = null,
    ): bool {
        if (!isset($this->abilities[$ability])) {
            return false;
        }

        return ($this->abilities[$ability])($user, $resource);
    }

    public function authorize(
        AuthenticatableInterface $user,
        string $ability,
        mixed $resource = null,
    ): void {
        if (!$this->allows($user, $ability, $resource)) {
            throw new AuthorizationException('The requested action is not authorized.');
        }
    }
}
