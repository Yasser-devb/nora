<?php

declare(strict_types=1);

namespace Nora\Tests\Integration;

use Nora\Core\Application;
use Nora\Core\ExceptionHandler;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Logging\StructuredLogger;
use NoraWebsite\Site\PageRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'examples'
    . DIRECTORY_SEPARATOR . 'nora-website' . DIRECTORY_SEPARATOR . 'src'
    . DIRECTORY_SEPARATOR . 'Site' . DIRECTORY_SEPARATOR . 'PageRenderer.php';

final class NoraWebsiteErrorPageTest extends TestCase
{
    public function testUnexpectedExceptionProducesBrandedSafeHtmlResponse(): void
    {
        $templateDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'examples'
            . DIRECTORY_SEPARATOR . 'nora-website' . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'views';
        $renderer = new PageRenderer($templateDirectory, '');
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $handler = new ExceptionHandler(
            new StructuredLogger($stream),
            false,
            static fn (int $status): Response => new Response(
                $renderer->errorPage($status),
                $status,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        );
        $app = new Application(exceptionHandler: $handler);
        $app->route('GET', '/failure', static function (): Response {
            throw new RuntimeException('private internal detail');
        });

        $response = $app->handle(Request::fromUri('GET', '/failure'));
        fclose($stream);

        self::assertSame(500, $response->status());
        self::assertStringContainsString("Something didn't go as expected.", $response->body());
        self::assertStringContainsString('Nora Framework', $response->body());
        self::assertSame('text/html; charset=utf-8', $response->headers()['Content-Type']);
        self::assertStringNotContainsString('private internal detail', $response->body());
    }
}
