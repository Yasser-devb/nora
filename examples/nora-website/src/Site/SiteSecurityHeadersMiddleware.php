<?php

declare(strict_types=1);

namespace NoraWebsite\Site;

use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;

final class SiteSecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $contentSecurityPolicy)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        return $next($request)
            ->withHeader('Content-Security-Policy', $this->contentSecurityPolicy)
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
