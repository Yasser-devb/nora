<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Core\Application;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoutingTest extends TestCase
{
    public function testDispatchesMatchingRouteAndProvidesParameters(): void
    {
        $app = new Application();
        $app->route('GET', '/users/{id}', static function (Request $request, array $parameters): Response {
            return Response::json(['id' => $parameters['id']]);
        });

        $response = $app->handle(Request::fromUri('GET', '/users/42'));

        self::assertSame(200, $response->status());
        self::assertSame('{"id":"42"}', $response->body());
    }

    public function testReturnsNotFoundForUnmatchedPath(): void
    {
        $response = (new Application())->handle(Request::fromUri('GET', '/missing'));

        self::assertSame(404, $response->status());
    }

    public function testCustomFallbackHandlesOnlyUnmatchedPathsAndAlwaysReturnsNotFound(): void
    {
        $app = new Application();
        $app->route('GET', '/known', static fn (): Response => Response::text('known'));
        $app->fallback(static fn (Request $request): Response => Response::text('branded: ' . $request->path()));

        self::assertSame('known', $app->handle(Request::fromUri('GET', '/known'))->body());
        $missing = $app->handle(Request::fromUri('GET', '/missing/deep'));
        self::assertSame(404, $missing->status());
        self::assertSame('branded: /missing/deep', $missing->body());

        $methodMismatch = $app->handle(Request::fromUri('POST', '/known'));
        self::assertSame(405, $methodMismatch->status());
        self::assertSame('GET', $methodMismatch->headers()['Allow']);
    }

    public function testReturnsAllowedMethodsWhenPathMatchesButMethodDoesNot(): void
    {
        $app = new Application();
        $app->route('GET', '/users', static fn (): Response => Response::text('users'));

        $response = $app->handle(Request::fromUri('POST', '/users'));

        self::assertSame(405, $response->status());
        self::assertSame('GET', $response->headers()['Allow']);
    }

    public function testMiddlewareRunsInRegistrationOrderAndCanShortCircuit(): void
    {
        $events = [];
        $app = new Application();
        $app->middleware(new RecordingMiddleware('first', $events));
        $app->middleware(new RecordingMiddleware('second', $events));
        $app->route('GET', '/', static fn (): Response => Response::text('ok'));

        $app->handle(Request::fromUri('GET', '/'));

        self::assertSame(
            ['first:before', 'second:before', 'second:after', 'first:after'],
            $events,
        );
    }

    public function testMiddlewareCanShortCircuitTheRoute(): void
    {
        $app = new Application();
        $app->middleware(new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return Response::text('stopped', 403);
            }
        });
        $app->route('GET', '/', static fn (): Response => Response::text('route'));

        $response = $app->handle(Request::fromUri('GET', '/'));

        self::assertSame(403, $response->status());
        self::assertSame('stopped', $response->body());
    }

    public function testRoutesAreMatchedInRegistrationOrder(): void
    {
        $app = new Application();
        $app->route('GET', '/users/{id}', static fn (): Response => Response::text('parameter'));
        $app->route('GET', '/users/me', static fn (): Response => Response::text('literal'));

        $response = $app->handle(Request::fromUri('GET', '/users/me'));

        self::assertSame('parameter', $response->body());
    }

    public function testRejectsDuplicateRouteParameterNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Application())->route('GET', '/users/{id}/posts/{id}', static fn (): Response => Response::text(''));
    }

    public function testRejectsQueryStringInRoutePattern(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Application())->route('GET', '/users?active=1', static fn (): Response => Response::text(''));
    }
}

/**
 * @internal
 */
final class RecordingMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        private readonly string $name,
        private array &$events,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->events[] = $this->name . ':before';
        $response = $next($request);
        $this->events[] = $this->name . ':after';

        return $response;
    }
}
