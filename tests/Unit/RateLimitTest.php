<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use InvalidArgumentException;
use Nora\Core\Application;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Security\FileRateLimitStore;
use Nora\Security\RateLimitMiddleware;
use Nora\Security\RateLimitStorageException;
use Nora\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_file($path) || is_link($path)) {
                    unlink($path);
                } elseif (is_dir($path)) {
                    rmdir($path);
                }
            }
            rmdir($directory);
        }
    }

    public function testAllowsRequestsThroughConfiguredLimitAndDeniesTheNextAttempt(): void
    {
        $now = 120;
        $limiter = $this->limiter(2, 60, $now);

        self::assertTrue($limiter->consume('route:login')->allowed());
        $second = $limiter->consume('route:login');
        self::assertTrue($second->allowed());
        self::assertSame(0, $second->remaining());

        $denied = $limiter->consume('route:login');
        self::assertFalse($denied->allowed());
        self::assertSame(60, $denied->retryAfterSeconds());
        self::assertSame(60, $denied->resetAfterSeconds());
    }

    public function testAllowanceResetsAtWindowBoundaryAndRetryAfterTracksTime(): void
    {
        $now = 119;
        $limiter = $this->limiter(1, 60, static function () use (&$now): int {
            return $now;
        });
        self::assertTrue($limiter->consume('account:42')->allowed());
        self::assertSame(1, $limiter->consume('account:42')->retryAfterSeconds());

        $now = 120;
        $reset = $limiter->consume('account:42');
        self::assertTrue($reset->allowed());
        self::assertSame(60, $reset->resetAfterSeconds());
    }

    public function testDifferentKeysUseIndependentBuckets(): void
    {
        $limiter = $this->limiter(1, 60, 120);
        self::assertTrue($limiter->consume('account:one')->allowed());
        self::assertTrue($limiter->consume('account:two')->allowed());
        self::assertFalse($limiter->consume('account:one')->allowed());
    }

    public function testClockMovingBackwardsFailsClosedInsteadOfResettingBucket(): void
    {
        $now = 120;
        $limiter = $this->limiter(1, 60, static function () use (&$now): int {
            return $now;
        });
        self::assertTrue($limiter->consume('route:login')->allowed());

        $now = 60;
        $this->expectException(RateLimitStorageException::class);
        $limiter->consume('route:login');
    }

    public function testRejectsInvalidLimitsWindowsAndMalformedKeys(): void
    {
        $store = new FileRateLimitStore($this->directory());
        foreach ([[0, 60], [1_000_001, 60], [1, 0], [1, 31_536_001]] as [$limit, $window]) {
            try {
                new RateLimiter($store, $limit, $window);
                self::fail('Expected invalid rate-limit configuration to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $limiter = new RateLimiter($store, 1, 60, static fn (): int => 120);
        foreach (['', '../secret', 'has space', "control\n", str_repeat('a', 129), 'clé'] as $key) {
            try {
                $limiter->consume($key);
                self::fail('Expected malformed bucket key to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMiddlewareAddsMetadataAndPreservesOrdinaryResponse(): void
    {
        $app = new Application();
        $app->middleware(new RateLimitMiddleware(
            $this->limiter(2, 60, 120),
            static fn (Request $request): string => 'route:health',
        ));
        $app->route('GET', '/health', static fn (): Response => new Response('healthy', 202, ['X-App' => 'ok']));

        $response = $app->handle(Request::fromUri('GET', '/health'));

        self::assertSame(202, $response->status());
        self::assertSame('healthy', $response->body());
        self::assertSame('ok', $response->headers()['X-App']);
        self::assertSame('2', $response->headers()['RateLimit-Limit']);
        self::assertSame('1', $response->headers()['RateLimit-Remaining']);
        self::assertSame('60', $response->headers()['RateLimit-Reset']);
        self::assertArrayNotHasKey('Retry-After', $response->headers());
    }

    public function testMiddlewareReturns429AndRetryMetadataWhenBucketIsExhausted(): void
    {
        $app = new Application();
        $app->middleware(new RateLimitMiddleware(
            $this->limiter(1, 60, 120),
            static fn (Request $request): string => 'route:login',
        ));
        $app->route('POST', '/login', static fn (): Response => Response::text('processed'));

        $app->handle(Request::fromUri('POST', '/login'));
        $response = $app->handle(Request::fromUri('POST', '/login'));

        self::assertSame(429, $response->status());
        self::assertSame('Too Many Requests', $response->body());
        self::assertSame('1', $response->headers()['RateLimit-Limit']);
        self::assertSame('0', $response->headers()['RateLimit-Remaining']);
        self::assertSame('60', $response->headers()['RateLimit-Reset']);
        self::assertSame('60', $response->headers()['Retry-After']);
    }

    public function testForwardedIpHeadersDoNotChangeApplicationControlledBucketKey(): void
    {
        $app = new Application();
        $app->middleware(new RateLimitMiddleware(
            $this->limiter(1, 60, 120),
            static fn (Request $request): string => 'route:login',
        ));
        $app->route('POST', '/login', static fn (): Response => Response::text('processed'));

        self::assertSame(
            200,
            $app->handle(Request::fromUri('POST', '/login', ['X-Forwarded-For' => '198.51.100.10']))->status(),
        );
        self::assertSame(
            429,
            $app->handle(Request::fromUri('POST', '/login', ['X-Forwarded-For' => '203.0.113.99']))->status(),
        );
    }

    public function testMiddlewareCanLeaveRequestsUnmeteredWhenResolverReturnsNull(): void
    {
        $app = new Application();
        $app->middleware(new RateLimitMiddleware(
            $this->limiter(1, 60, 120),
            static fn (Request $request): ?string => null,
        ));
        $app->route('GET', '/', static fn (): Response => Response::text('ok'));

        for ($request = 0; $request < 3; $request++) {
            $response = $app->handle(Request::fromUri('GET', '/'));
            self::assertSame(200, $response->status());
            self::assertArrayNotHasKey('RateLimit-Limit', $response->headers());
        }
    }

    public function testMiddlewareFailsClosedWithGeneric503OnCorruptStorage(): void
    {
        $directory = $this->directory();
        $store = new FileRateLimitStore($directory);
        $limiter = new RateLimiter($store, 1, 60, static fn (): int => 120);
        $limiter->consume('route:login');
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'bucket-' . hash('sha256', 'route:login') . '.json', '{');

        $app = new Application();
        $app->middleware(new RateLimitMiddleware($limiter, static fn (Request $request): string => 'route:login'));
        $response = $app->handle(Request::fromUri('POST', '/login'));

        self::assertSame(503, $response->status());
        self::assertSame('Service Unavailable', $response->body());
        self::assertStringNotContainsString($directory, $response->body());
    }

    public function testInvalidLockPathFailsClosedWithGeneric503(): void
    {
        $directory = $this->directory();
        $store = new FileRateLimitStore($directory);
        mkdir($directory . DIRECTORY_SEPARATOR . 'limiter.lock');
        $limiter = new RateLimiter($store, 1, 60, static fn (): int => 120);

        $app = new Application();
        $app->middleware(new RateLimitMiddleware($limiter, static fn (Request $request): string => 'route:login'));
        $response = $app->handle(Request::fromUri('POST', '/login'));

        self::assertSame(503, $response->status());
        self::assertSame('Service Unavailable', $response->body());
    }

    public function testFilesystemPermissionFailureFailsClosedWhenPermissionsAreEnforced(): void
    {
        $directory = $this->directory();
        $store = new FileRateLimitStore($directory);
        $limiter = new RateLimiter($store, 2, 60, static fn (): int => 120);
        $limiter->consume('route:login');

        if (!@chmod($directory, 0500)) {
            self::markTestSkipped('The current platform does not allow changing directory permissions.');
        }

        try {
            $app = new Application();
            $app->middleware(new RateLimitMiddleware(
                $limiter,
                static fn (Request $request): string => 'route:login',
            ));
            $response = $app->handle(Request::fromUri('POST', '/login'));
        } finally {
            @chmod($directory, 0700);
        }

        if ($response->status() !== 503) {
            self::markTestSkipped('The current process can still write through the changed directory permissions.');
        }
        self::assertSame('Service Unavailable', $response->body());
    }

    public function testCleanupRemovesOnlyExpiredRateLimitFiles(): void
    {
        $directory = $this->directory();
        $store = new FileRateLimitStore($directory);
        $limiter = new RateLimiter($store, 1, 10, static fn (): int => 10);
        $limiter->consume('route:short');
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'application-data.txt', 'keep');
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'bucket-not-a-digest.json', 'keep');

        self::assertSame(1, $store->cleanup(20));
        self::assertSame('keep', file_get_contents($directory . DIRECTORY_SEPARATOR . 'application-data.txt'));
        self::assertSame('keep', file_get_contents($directory . DIRECTORY_SEPARATOR . 'bucket-not-a-digest.json'));
        self::assertFileDoesNotExist(
            $directory . DIRECTORY_SEPARATOR . 'bucket-' . hash('sha256', 'route:short') . '.json',
        );
    }

    public function testCleanupDoesNotFollowBucketSymlinksWhenSupported(): void
    {
        $directory = $this->directory();
        $store = new FileRateLimitStore($directory);
        $outsideFile = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'nora-rate-sentinel-'
            . bin2hex(random_bytes(8));
        file_put_contents($outsideFile, 'preserve');
        $linkPath = $directory . DIRECTORY_SEPARATOR
            . 'bucket-'
            . str_repeat('a', 64)
            . '.json';
        try {
            if (!@symlink($outsideFile, $linkPath)) {
                self::markTestSkipped('The current platform does not permit creating symbolic links.');
            }

            self::assertSame(0, $store->cleanup(PHP_INT_MAX));
            self::assertSame('preserve', file_get_contents($outsideFile));
            self::assertTrue(is_link($linkPath));
        } finally {
            if (is_link($linkPath)) {
                unlink($linkPath);
            }
            if (file_exists($outsideFile)) {
                unlink($outsideFile);
            }
        }
    }

    public function testStoredBucketDoesNotContainTheRawKey(): void
    {
        $directory = $this->directory();
        $limiter = new RateLimiter(new FileRateLimitStore($directory), 1, 60, static fn (): int => 120);
        $limiter->consume('sensitive:account-identifier');

        $path = $directory . DIRECTORY_SEPARATOR
            . 'bucket-'
            . hash('sha256', 'sensitive:account-identifier')
            . '.json';
        self::assertFileExists($path);
        self::assertStringNotContainsString('sensitive:account-identifier', file_get_contents($path));
    }

    public function testConcurrentProcessUpdatesAreAtomicWhenProcessControlIsAvailable(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable; concurrent process locking cannot be exercised.');
        }

        $directory = $this->directory();
        new FileRateLimitStore($directory);
        $workerPath = $directory . DIRECTORY_SEPARATOR . 'rate-limit-worker.php';
        $autoloadPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';
        $workerCode = "<?php\nrequire "
            . var_export($autoloadPath, true)
            . ";\n\$limiter = new \\Nora\\Security\\RateLimiter("
            . "new \\Nora\\Security\\FileRateLimitStore("
            . var_export($directory, true)
            . "), 40, 60, static fn (): int => 120);\n"
            . "for (\$i = 0; \$i < 10; \$i++) {\n"
            . "    if (!\$limiter->consume('concurrent:bucket')->allowed()) { exit(2); }\n"
            . "}\n";
        self::assertNotFalse(file_put_contents($workerPath, $workerCode));

        $workers = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $process = proc_open(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath),
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes[1], $pipes[2]];
        }

        foreach ($workers as [$process, $stdout, $stderr]) {
            $output = stream_get_contents($stdout);
            $errors = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            self::assertSame('', $output);
            self::assertSame('', $errors);
            self::assertSame(0, proc_close($process));
        }

        $result = (new RateLimiter(
            new FileRateLimitStore($directory),
            40,
            60,
            static fn (): int => 120,
        ))->consume('concurrent:bucket');
        self::assertFalse($result->allowed());
    }

    private function limiter(int $limit, int $window, int|callable $clock): RateLimiter
    {
        $clock = is_int($clock) ? static fn (): int => $clock : $clock;

        return new RateLimiter(
            new FileRateLimitStore($this->directory()),
            $limit,
            $window,
            $clock,
        );
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'nora-rate-limit-'
            . bin2hex(random_bytes(8));
        $this->directories[] = $directory;

        return $directory;
    }
}
