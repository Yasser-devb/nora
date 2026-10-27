<?php

declare(strict_types=1);

namespace Nora\Security;

interface AuthenticatorInterface
{
    public function user(): ?AuthenticatableInterface;

    public function login(AuthenticatableInterface $user): void;

    public function logout(): void;
}
