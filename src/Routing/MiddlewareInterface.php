<?php

declare(strict_types=1);

namespace Nora\Routing;

use Nora\Http\Request;
use Nora\Http\Response;

interface MiddlewareInterface
{
    public function process(Request $request, callable $next): Response;
}
