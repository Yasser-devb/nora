<?php

declare(strict_types=1);

namespace Nora\Security;

use Closure;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;

final class RateLimitMiddleware implements MiddlewareInterface
{
    private readonly Closure $keyResolver;

    /**
     * The resolver returns null to leave a request unmetered.
     *
     * @param callable(Request): string|null $keyResolver
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        callable $keyResolver,
    ) {
        $this->keyResolver = Closure::fromCallable($keyResolver);
    }

    public function process(Request $request, callable $next): Response
    {
        $key = ($this->keyResolver)($request);
        if ($key === null) {
            return $next($request);
        }

        try {
            $result = $this->limiter->consume($key);
        } catch (RateLimitStorageException) {
            return Response::text('Service Unavailable', 503);
        }

        $response = $next($request)
            ->withHeader('RateLimit-Limit', (string) $result->limit())
            ->withHeader('RateLimit-Remaining', (string) $result->remaining())
            ->withHeader('RateLimit-Reset', (string) $result->resetAfterSeconds());

        if ($result->allowed()) {
            return $response;
        }

        return Response::text('Too Many Requests', 429)
            ->withHeader('RateLimit-Limit', (string) $result->limit())
            ->withHeader('RateLimit-Remaining', '0')
            ->withHeader('RateLimit-Reset', (string) $result->resetAfterSeconds())
            ->withHeader('Retry-After', (string) $result->retryAfterSeconds());
    }
}
