<?php

declare(strict_types=1);

namespace Nora\Routing;

use Nora\Http\Request;
use Nora\Http\Response;
use RuntimeException;

final class MiddlewarePipeline
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function handle(Request $request, array $middleware, callable $destination): Response
    {
        $next = static function (Request $request) use ($destination): Response {
            $response = $destination($request);

            if (!$response instanceof Response) {
                throw new RuntimeException('The request destination must return a Response.');
            }

            return $response;
        };

        foreach (array_reverse($middleware) as $layer) {
            $downstream = $next;
            $next = static function (Request $request) use ($layer, $downstream): Response {
                return $layer->process($request, $downstream);
            };
        }

        return $next($request);
    }
}
