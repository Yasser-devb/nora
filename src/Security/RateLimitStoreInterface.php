<?php

declare(strict_types=1);

namespace Nora\Security;

interface RateLimitStoreInterface
{
    /**
     * Atomically record an attempt and return its count within the current window.
     *
     * $bucketHash is a lowercase SHA-256 digest, not the caller's raw key.
     */
    public function consume(
        string $bucketHash,
        int $limit,
        int $windowStart,
        int $resetAt,
    ): int;

    /**
     * Remove expired buckets and return the number of bucket files removed.
     */
    public function cleanup(int $now): int;
}
