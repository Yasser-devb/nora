<?php

declare(strict_types=1);

namespace Nora\Security;

use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionManager $session)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->session->start();

        try {
            return $next($request);
        } finally {
            $this->session->close();
        }
    }
}
