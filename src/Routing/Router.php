<?php

declare(strict_types=1);

namespace Nora\Routing;

use Closure;
use InvalidArgumentException;
use Nora\Http\Request;
use Nora\Http\Response;

final class Router
{
    /**
     * @var list<array{
     *     method: string,
     *     pattern: string,
     *     parameterNames: list<string>,
     *     handler: callable
     * }>
     */
    private array $routes = [];

    private ?Closure $fallback = null;

    public function fallback(callable $handler): void
    {
        $this->fallback = Closure::fromCallable($handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $method = strtoupper($method);

        if (preg_match('/^[A-Z]+$/D', $method) !== 1) {
            throw new InvalidArgumentException('The route method is invalid.');
        }

        [$regex, $parameterNames] = $this->compilePattern($pattern);
        $this->routes[] = [
            'method' => $method,
            'pattern' => $regex,
            'parameterNames' => $parameterNames,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $request->path(), $matches) !== 1) {
                continue;
            }

            $allowedMethods[] = $route['method'];

            if ($route['method'] !== $request->method()) {
                continue;
            }

            $parameters = [];
            foreach ($route['parameterNames'] as $name) {
                $parameters[$name] = $matches[$name];
            }

            $response = ($route['handler'])($request, $parameters);
            if (!$response instanceof Response) {
                throw new \RuntimeException('A route handler must return a Response.');
            }

            return $response;
        }

        if ($allowedMethods !== []) {
            $allowedMethods = array_values(array_unique($allowedMethods));
            sort($allowedMethods);

            return Response::text('Method Not Allowed', 405)
                ->withHeader('Allow', implode(', ', $allowedMethods));
        }

        if ($this->fallback !== null) {
            $response = ($this->fallback)($request);
            if (!$response instanceof Response) {
                throw new \RuntimeException('A fallback handler must return a Response.');
            }

            return $response->status() === 404
                ? $response
                : new Response($response->body(), 404, $response->headers());
        }

        return Response::text('Not Found', 404);
    }

    /**
     * @return array{string, list<string>}
     */
    private function compilePattern(string $pattern): array
    {
        if (
            $pattern === ''
            || $pattern[0] !== '/'
            || str_contains($pattern, "\0")
            || str_contains($pattern, '?')
            || str_contains($pattern, '#')
        ) {
            throw new InvalidArgumentException('A route pattern must be an absolute URL path.');
        }

        $segments = explode('/', trim($pattern, '/'));
        if ($pattern === '/') {
            $segments = [];
        }

        $parameterNames = [];
        $compiled = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $segment, $match) === 1) {
                if (in_array($match[1], $parameterNames, true)) {
                    throw new InvalidArgumentException('Route parameter names must be unique.');
                }

                $parameterNames[] = $match[1];
                $compiled[] = '(?P<' . $match[1] . '>[^/]+)';
                continue;
            }

            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new InvalidArgumentException('Route parameters must occupy an entire path segment.');
            }

            $compiled[] = preg_quote($segment, '#');
        }

        $regex = $compiled === []
            ? '#^/$#D'
            : '#^/' . implode('/', $compiled) . '/?$#D';

        return [$regex, $parameterNames];
    }
}
