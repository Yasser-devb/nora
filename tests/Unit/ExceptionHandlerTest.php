<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Core\Application;
use Nora\Core\ExceptionHandler;
use Nora\Http\HttpException;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Logging\StructuredLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionHandlerTest extends TestCase
{
    public function testApplicationReturnsSafeServerErrorAndLogsExceptionType(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $handler = new ExceptionHandler(new StructuredLogger($stream));
        $app = new Application(exceptionHandler: $handler);
        $app->route('GET', '/', static function (): Response {
            throw new RuntimeException('private database details');
        });

        $response = $app->handle(Request::fromUri('GET', '/'));

        rewind($stream);
        $log = stream_get_contents($stream);
        fclose($stream);
        self::assertSame(500, $response->status());
        self::assertSame('Internal Server Error', $response->body());
        self::assertStringContainsString(RuntimeException::class, (string) $log);
        self::assertStringNotContainsString('private database details', (string) $log);
        self::assertStringNotContainsString('private database details', $response->body());
    }

    public function testHttpExceptionUsesItsStatusAndSafeClientMessage(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $handler = new ExceptionHandler(new StructuredLogger($stream));
        $response = $handler->handle(new HttpException(403, 'Forbidden'));
        fclose($stream);

        self::assertSame(403, $response->status());
        self::assertSame('Forbidden', $response->body());
    }

    public function testOptionalErrorResponseFactoryCanRenderAResponseWithoutExposingExceptionDetails(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $handler = new ExceptionHandler(
            new StructuredLogger($stream),
            true,
            static fn (int $status): Response => new Response('<h1>Safe error page</h1>', 200),
        );

        $response = $handler->handle(new RuntimeException('private server detail'));
        fclose($stream);

        self::assertSame(500, $response->status());
        self::assertSame('<h1>Safe error page</h1>', $response->body());
        self::assertStringNotContainsString('private server detail', $response->body());
    }

    public function testStructuredLoggerRedactsSensitiveContextRecursively(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $logger = new StructuredLogger($stream);
        $logger->log('info', 'event', [
            'user_id' => 12,
            'credentials' => ['api_key' => 'sensitive'],
            'authorization' => 'Bearer hidden',
            'details' => 'password=also-hidden',
            'object' => (object) ['secret' => 'hidden'],
        ]);
        rewind($stream);
        $record = json_decode((string) stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR);
        fclose($stream);

        self::assertSame(12, $record['context']['user_id']);
        self::assertSame('[REDACTED]', $record['context']['credentials']['api_key']);
        self::assertSame('[REDACTED]', $record['context']['authorization']);
        self::assertSame('password=[REDACTED]', $record['context']['details']);
        self::assertSame('[OMITTED]', $record['context']['object']);
    }

    public function testStructuredLoggerHonorsMinimumLevel(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $logger = new StructuredLogger($stream, 'warning');
        $logger->log('info', 'discarded');
        $logger->log('error', 'retained');
        rewind($stream);
        $records = array_filter(explode(PHP_EOL, trim((string) stream_get_contents($stream))));
        fclose($stream);

        self::assertCount(1, $records);
        self::assertStringContainsString('retained', $records[0]);
    }
}
