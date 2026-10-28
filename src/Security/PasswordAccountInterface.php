<?php

declare(strict_types=1);

namespace Nora\Security;

interface PasswordAccountInterface extends AuthenticatableInterface
{
    public function email(): string;

    public function passwordHash(): string;

    public function isEnabled(): bool;
}
