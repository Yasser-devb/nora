<?php

declare(strict_types=1);

namespace Nora\Security;

final readonly class PasswordLoginResult
{
    private function __construct(
        private bool $authenticated,
        private ?int $retryAfterSeconds,
    ) {
    }

    public static function authenticated(): self
    {
        return new self(true, null);
    }

    public static function rejected(): self
    {
        return new self(false, null);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(false, $retryAfterSeconds);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
