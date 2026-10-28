<?php

declare(strict_types=1);

namespace Nora\Security;

final readonly class RateLimitResult
{
    public function __construct(
        private bool $allowed,
        private int $limit,
        private int $remaining,
        private int $resetAfterSeconds,
    ) {
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }

    public function resetAfterSeconds(): int
    {
        return $this->resetAfterSeconds;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->allowed ? null : $this->resetAfterSeconds;
    }
}
