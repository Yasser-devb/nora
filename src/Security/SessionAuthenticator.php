<?php

declare(strict_types=1);

namespace Nora\Security;

use Closure;
use InvalidArgumentException;

final class SessionAuthenticator implements AuthenticatorInterface
{
    private const USER_KEY = 'auth.user_id';

    /**
     * @param Closure(string): ?AuthenticatableInterface $userResolver
     */
    public function __construct(
        private readonly SessionStoreInterface $session,
        private readonly Closure $userResolver,
    ) {
    }

    public function user(): ?AuthenticatableInterface
    {
        $identifier = $this->session->get(self::USER_KEY);
        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        return ($this->userResolver)($identifier);
    }

    public function login(AuthenticatableInterface $user): void
    {
        $identifier = $user->authIdentifier();
        if ($identifier === '') {
            throw new InvalidArgumentException('An authenticated user identifier cannot be empty.');
        }

        $this->session->regenerateId();
        (new CsrfTokenManager($this->session))->invalidate();
        $this->session->set(self::USER_KEY, $identifier);
    }

    public function logout(): void
    {
        $this->session->remove(self::USER_KEY);
        $this->session->regenerateId();
        (new CsrfTokenManager($this->session))->invalidate();
    }
}
