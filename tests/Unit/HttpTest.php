<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use InvalidArgumentException;
use Nora\Core\Application;
use Nora\Core\ApplicationBootstrap;
use Nora\Http\Request;
use Nora\Http\Response;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testParsesQueryParametersAndJsonBody(): void
    {
        $request = Request::fromUri(
            'post',
            '/api/items?limit=5',
            ['Content-Type' => 'application/json'],
            '{"name":"book"}',
        );

        self::assertSame('POST', $request->method());
        self::assertSame(['limit' => '5'], $request->query());
        self::assertSame(['name' => 'book'], $request->json());
    }

    public function testJsonResponseSetsContentTypeAndBody(): void
    {
        $response = Response::json(['ok' => true], 201);

        self::assertSame(201, $response->status());
        self::assertSame('{"ok":true}', $response->body());
        self::assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
    }

    public function testWithHeaderReplacesExistingHeaderWithoutCaseDuplicates(): void
    {
        $response = (new Response('', 200, ['Content-Type' => 'text/plain']))
            ->withHeader('content-type', 'application/json');

        self::assertSame(['content-type' => 'application/json'], $response->headers());
    }

    public function testRejectsHeaderLineBreaks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response('', 200, ['X-Test' => "value\r\nInjected: true"]);
    }

    public function testRejectsQueryStringInRequestPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Request('GET', '/items?admin=true');
    }

    public function testBuildsRequestFromServerVariables(): void
    {
        $request = Request::fromGlobals(
            [
                'REQUEST_METHOD' => 'PATCH',
                'REQUEST_URI' => '/api/items?id=7',
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ],
            null,
            '{"name":"pen"}',
        );

        self::assertSame('PATCH', $request->method());
        self::assertSame('/api/items', $request->path());
        self::assertSame('application/json', $request->header('Accept'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame(['id' => '7'], $request->query());
        self::assertSame(['name' => 'pen'], $request->json());
    }

    public function testApplicationRunUsesRequestLifecycleAndReturnsResponse(): void
    {
        $app = new Application();
        $app->route('GET', '/health', static fn (): Response => Response::text('healthy'));

        ob_start();
        $response = $app->run(Request::fromUri('GET', '/health'));
        $output = ob_get_clean();

        self::assertSame(200, $response->status());
        self::assertSame('healthy', $response->body());
        self::assertSame('healthy', $output);
    }

    public function testApplicationBootstrapLoadsAndRegistersRoutes(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-app-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($root));
        $routes = $root . DIRECTORY_SEPARATOR . 'routes.php';
        file_put_contents(
            $routes,
            "<?php return static function (\\Nora\\Core\\Application \$app): void {"
            . "\$app->route('GET', '/booted', static fn (): \\Nora\\Http\\Response =>"
            . "\\Nora\\Http\\Response::text('booted'));};",
        );

        try {
            $app = (new ApplicationBootstrap())->boot($root);
            self::assertSame(
                'booted',
                $app->handle(Request::fromUri('GET', '/booted'))->body(),
            );
        } finally {
            unlink($routes);
            rmdir($root);
        }
    }
}
