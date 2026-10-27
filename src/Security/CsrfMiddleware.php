<?php

declare(strict_types=1);

namespace Nora\Security;

use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CsrfTokenManager $tokens)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $provided = $request->header('X-CSRF-Token');
        if ($provided === null) {
            $formToken = $request->form()['_csrf'] ?? null;
            $provided = is_string($formToken) ? $formToken : null;
        }

        if (!$this->tokens->isValid($provided)) {
            return Response::text('CSRF token mismatch', 403);
        }

        return $next($request);
    }
}
