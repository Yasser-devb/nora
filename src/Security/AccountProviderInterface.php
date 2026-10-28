<?php

declare(strict_types=1);

namespace Nora\Security;

interface AccountProviderInterface
{
    public function findByEmail(string $email): ?PasswordAccountInterface;

    public function updatePasswordHash(string $identifier, string $passwordHash): void;
}
