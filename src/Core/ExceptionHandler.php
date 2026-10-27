<?php

declare(strict_types=1);

namespace Nora\Core;

use Closure;
use Nora\Http\HttpException;
use Nora\Http\Response;
use Nora\Logging\LoggerInterface;
use Nora\Logging\StructuredLogger;
use Throwable;

final class ExceptionHandler
{
    private readonly LoggerInterface $logger;

    private readonly ?Closure $errorResponseFactory;

    public function __construct(
        ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
        ?callable $errorResponseFactory = null,
    ) {
        $this->logger = $logger ?? StructuredLogger::toErrorLog();
        $this->errorResponseFactory = $errorResponseFactory === null
            ? null
            : Closure::fromCallable($errorResponseFactory);
    }

    public function handle(Throwable $exception): Response
    {
        $status = $exception instanceof HttpException ? $exception->status() : 500;
        $this->logger->log(
            $status >= 500 ? 'error' : 'warning',
            'Request failed.',
            [
                'exception_type' => $exception::class,
                'http_status' => $status,
            ],
        );

        if ($this->errorResponseFactory !== null) {
            $response = ($this->errorResponseFactory)($status);
            if (!$response instanceof Response) {
                throw new \RuntimeException('An error response factory must return a Response.');
            }

            return $response->status() === $status
                ? $response
                : new Response($response->body(), $status, $response->headers());
        }

        if ($exception instanceof HttpException && $status < 500) {
            return Response::text($exception->getMessage(), $status);
        }

        if ($this->debug) {
            return Response::text(
                $exception::class . ': ' . $exception->getMessage(),
                $status,
            );
        }

        return Response::text(
            $status === 404 ? 'Not Found' : 'Internal Server Error',
            $status,
        );
    }
}
