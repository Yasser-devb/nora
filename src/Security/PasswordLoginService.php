<?php

declare(strict_types=1);

namespace Nora\Security;

final class PasswordLoginService
{
    private static ?string $dummyPasswordHash = null;

    public function __construct(
        private readonly AccountProviderInterface $accounts,
        private readonly AuthenticatorInterface $authenticator,
        private readonly PasswordHasher $passwordHasher,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function attempt(string $email, string $password, string $rateLimitKey): PasswordLoginResult
    {
        $rateLimit = $this->rateLimiter->consume($rateLimitKey);
        if (!$rateLimit->allowed()) {
            return PasswordLoginResult::throttled($rateLimit->retryAfterSeconds() ?? 1);
        }

        $account = $this->accounts->findByEmail($email);
        $passwordHash = $account?->passwordHash() ?? self::dummyPasswordHash($this->passwordHasher);
        $passwordMatches = $this->passwordHasher->verify($password, $passwordHash);

        if ($account === null || !$passwordMatches || !$account->isEnabled()) {
            return PasswordLoginResult::rejected();
        }

        if ($this->passwordHasher->needsRehash($passwordHash)) {
            $this->accounts->updatePasswordHash($account->authIdentifier(), $this->passwordHasher->hash($password));
        }

        $this->authenticator->login($account);

        return PasswordLoginResult::authenticated();
    }

    private static function dummyPasswordHash(PasswordHasher $passwordHasher): string
    {
        return self::$dummyPasswordHash ??= $passwordHasher->hash(bin2hex(random_bytes(32)));
    }
}
