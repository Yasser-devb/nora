<?php

declare(strict_types=1);

namespace Nora\Security;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class RateLimiter
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly int $limit,
        private readonly int $windowSeconds,
        ?callable $clock = null,
    ) {
        if ($limit < 1 || $limit > 1_000_000) {
            throw new InvalidArgumentException('The rate limit must be between 1 and 1000000.');
        }
        if ($windowSeconds < 1 || $windowSeconds > 31_536_000) {
            throw new InvalidArgumentException('The rate-limit window must be between 1 and 31536000 seconds.');
        }

        $this->clock = Closure::fromCallable($clock ?? static fn (): int => time());
    }

    public function consume(string $key): RateLimitResult
    {
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key) !== 1) {
            throw new InvalidArgumentException(
                'A rate-limit key must contain 1 to 128 ASCII letters, digits, dots, underscores, colons, or hyphens.',
            );
        }

        $now = ($this->clock)();
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - $this->windowSeconds) {
            throw new RuntimeException('The rate-limit clock returned an invalid timestamp.');
        }

        $windowStart = intdiv($now, $this->windowSeconds) * $this->windowSeconds;
        $resetAt = $windowStart + $this->windowSeconds;
        $count = $this->store->consume(
            hash('sha256', $key),
            $this->limit,
            $windowStart,
            $resetAt,
        );

        if ($count < 1) {
            throw new RateLimitStorageException('The rate-limit store returned an invalid count.');
        }

        $allowed = $count <= $this->limit;

        return new RateLimitResult(
            $allowed,
            $this->limit,
            max(0, $this->limit - $count),
            $resetAt - $now,
        );
    }
}
