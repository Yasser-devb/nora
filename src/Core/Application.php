<?php

declare(strict_types=1);

namespace Nora\Core;

use Nora\Config\Config;
use Nora\Http\Request;
use Nora\Http\Response;
use Throwable;
use Nora\Routing\MiddlewareInterface;
use Nora\Routing\MiddlewarePipeline;
use Nora\Routing\Router;

final class Application
{
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    private readonly Config $config;

    public function __construct(
        private readonly Router $router = new Router(),
        private readonly MiddlewarePipeline $pipeline = new MiddlewarePipeline(),
        private readonly ExceptionHandler $exceptionHandler = new ExceptionHandler(),
        ?Config $config = null,
    ) {
        $this->config = $config ?? new Config();
    }

    public function route(string $method, string $pattern, callable $handler): self
    {
        $this->router->add($method, $pattern, $handler);

        return $this;
    }

    public function fallback(callable $handler): self
    {
        $this->router->fallback($handler);

        return $this;
    }

    public function middleware(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function prependMiddleware(MiddlewareInterface $middleware): self
    {
        array_unshift($this->middleware, $middleware);

        return $this;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->pipeline->handle(
                $request,
                $this->middleware,
                fn (Request $request): Response => $this->router->dispatch($request),
            );
        } catch (Throwable $exception) {
            return $this->exceptionHandler->handle($exception);
        }
    }

    public function run(?Request $request = null): Response
    {
        try {
            $request ??= Request::fromGlobals();
        } catch (Throwable $exception) {
            $response = $this->exceptionHandler->handle($exception);
            $response->send();

            return $response;
        }

        $response = $this->handle($request);
        $response->send();

        return $response;
    }
}
