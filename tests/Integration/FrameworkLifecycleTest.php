<?php

declare(strict_types=1);

namespace Nora\Tests\Integration;

use Nora\Core\ApplicationBootstrap;
use Nora\Core\ExceptionHandler;
use Nora\Logging\LoggerInterface;
use Nora\Http\Request;
use Nora\Http\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FrameworkLifecycleTest extends TestCase
{
    public function testBootstrapRunsAConfiguredRouteThroughTheHttpRequestLifecycle(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-integration-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($root));
        self::assertTrue(mkdir($root . DIRECTORY_SEPARATOR . 'views'));
        file_put_contents($root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'home.nora', '<h1>{{ name }}</h1>');
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'routes.php',
            "<?php return static function (\\Nora\\Core\\Application \$app) use (\$root): void {"
            . "\$renderer = new \\Nora\\View\\TemplateRenderer(\$root . '/views');"
            . "\$app->route('GET', '/', static fn (): \\Nora\\Http\\Response =>"
            . "\\Nora\\Http\\Response::text(\$renderer->render('home', ['name' => '<script>x</script>'])));};",
        );

        try {
            $app = (new ApplicationBootstrap())->boot($root);
            $response = $app->handle(Request::fromUri('GET', '/'));

            self::assertSame(200, $response->status());
            self::assertSame('<h1>&lt;script&gt;x&lt;/script&gt;</h1>', $response->body());
        } finally {
            unlink($root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'home.nora');
            rmdir($root . DIRECTORY_SEPARATOR . 'views');
            unlink($root . DIRECTORY_SEPARATOR . 'routes.php');
            rmdir($root);
        }
    }

    public function testAuthorizationExceptionBecomesForbiddenHttpResponse(): void
    {
        $logger = new IntegrationLogger();
        $app = new \Nora\Core\Application(exceptionHandler: new ExceptionHandler($logger));
        $app->route('GET', '/private', static function (): Response {
            throw new \Nora\Security\AuthorizationException();
        });

        $response = $app->handle(Request::fromUri('GET', '/private'));

        self::assertSame(403, $response->status());
        self::assertSame('The requested action is not authorized.', $response->body());
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function testBootstrapLoadsEnvironmentAndConfigAndUsesDebugSetting(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-config-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($root));
        self::assertTrue(mkdir($root . DIRECTORY_SEPARATOR . 'config'));
        file_put_contents($root . DIRECTORY_SEPARATOR . '.env', "APP_ENV=development\nAPP_DEBUG=true\n");
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            "<?php return ['app' => ['debug' => false]];",
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'development.php',
            "<?php return ['app' => ['name' => 'Nora']];",
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'routes.php',
            "<?php return static function (\\Nora\\Core\\Application \$app): void {"
            . "\$app->route('GET', '/environment', static fn (): \\Nora\\Http\\Response =>"
            . "\\Nora\\Http\\Response::text(\$app->config()->get('app.environment')));"
            . "\$app->route('GET', '/', static function (): \\Nora\\Http\\Response {"
            . "throw new \\RuntimeException('development detail');});};",
        );

        $previousEnv = getenv('APP_ENV');
        $previousDebug = getenv('APP_DEBUG');
        $previousEnvValue = $_ENV['APP_ENV'] ?? null;
        $hadEnvValue = array_key_exists('APP_ENV', $_ENV);
        $previousServerEnvValue = $_SERVER['APP_ENV'] ?? null;
        $hadServerEnvValue = array_key_exists('APP_ENV', $_SERVER);
        $previousDebugValue = $_ENV['APP_DEBUG'] ?? null;
        $hadDebugValue = array_key_exists('APP_DEBUG', $_ENV);
        $previousServerDebugValue = $_SERVER['APP_DEBUG'] ?? null;
        $hadServerDebugValue = array_key_exists('APP_DEBUG', $_SERVER);
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        unset($_ENV['APP_ENV'], $_ENV['APP_DEBUG'], $_SERVER['APP_ENV'], $_SERVER['APP_DEBUG']);
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);

        try {
            $app = (new ApplicationBootstrap(new \Nora\Logging\StructuredLogger($stream)))->boot($root);
            $response = $app->handle(Request::fromUri('GET', '/'));

            self::assertSame(500, $response->status());
            self::assertStringContainsString('development detail', $response->body());
            self::assertSame(
                'development',
                $app->handle(Request::fromUri('GET', '/environment'))->body(),
            );
        } finally {
            if ($previousEnv !== false) {
                putenv('APP_ENV=' . $previousEnv);
            } else {
                putenv('APP_ENV');
            }
            if ($previousDebug !== false) {
                putenv('APP_DEBUG=' . $previousDebug);
            } else {
                putenv('APP_DEBUG');
            }
            if ($hadEnvValue) {
                $_ENV['APP_ENV'] = $previousEnvValue;
            } else {
                unset($_ENV['APP_ENV']);
            }
            if ($hadServerEnvValue) {
                $_SERVER['APP_ENV'] = $previousServerEnvValue;
            } else {
                unset($_SERVER['APP_ENV']);
            }
            if ($hadDebugValue) {
                $_ENV['APP_DEBUG'] = $previousDebugValue;
            } else {
                unset($_ENV['APP_DEBUG']);
            }
            if ($hadServerDebugValue) {
                $_SERVER['APP_DEBUG'] = $previousServerDebugValue;
            } else {
                unset($_SERVER['APP_DEBUG']);
            }
            fclose($stream);
            unlink($root . DIRECTORY_SEPARATOR . '.env');
            unlink($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
            unlink($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'development.php');
            rmdir($root . DIRECTORY_SEPARATOR . 'config');
            unlink($root . DIRECTORY_SEPARATOR . 'routes.php');
            rmdir($root);
        }
    }
}

/**
 * @internal
 */
final class IntegrationLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
