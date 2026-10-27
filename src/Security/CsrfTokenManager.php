<?php

declare(strict_types=1);

namespace Nora\Security;

final class CsrfTokenManager
{
    public const SESSION_KEY = 'security.csrf_token';

    public function __construct(private readonly SessionStoreInterface $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $providedToken): bool
    {
        if ($providedToken === null || $providedToken === '' || !$this->session->has(self::SESSION_KEY)) {
            return false;
        }

        $knownToken = $this->session->get(self::SESSION_KEY);

        return is_string($knownToken) && hash_equals($knownToken, $providedToken);
    }

    public function invalidate(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
