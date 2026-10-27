<?php

declare(strict_types=1);

namespace Nora\Security;

interface AuthenticatableInterface
{
    public function authIdentifier(): string;
}
