<?php

declare(strict_types=1);

namespace Nora\Tests;

use Nora\Console\ConsoleApplication;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    public function testHelpAndVersionCommandsAreFunctional(): void
    {
        [$app, $output] = $this->application(sys_get_temp_dir());

        self::assertSame(0, $app->run(['--help']));
        self::assertSame(0, $app->run(['--version']));
        rewind($output);
        $result = stream_get_contents($output);
        fclose($output);

        self::assertStringContainsString('make:controller', (string) $result);
        self::assertStringContainsString('development build', (string) $result);
    }

    public function testScaffoldsStarterApplicationWithoutOverwriting(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(0, $app->run(['new', 'sample-app']));
            self::assertFileExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'composer.json');
            self::assertFileExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
            self::assertFileExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Reactive' . DIRECTORY_SEPARATOR . 'CounterPage.php');
            self::assertFileExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'counter.nora');
            self::assertDirectoryExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'migrations');
            $manifest = json_decode(
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame('*@dev', $manifest['require']['morave/nora']);
            foreach ([
                'public/index.php',
                'public/router.php',
                'src/routes.php',
                'src/Reactive/CounterPage.php',
            ] as $relativePath) {
                token_get_all(
                    (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'sample-app'
                        . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)),
                    TOKEN_PARSE,
                );
            }

            self::assertSame(1, $app->run(['new', 'sample-app']));
            self::assertSame(1, $app->run(['new', '..']));
            self::assertSame(1, $app->run(['new', 'CON']));
        } finally {
            fclose($output);
            $this->removeTree($workspace);
        }
    }

    public function testGeneratorsCreateClassesAndRejectExistingFiles(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(0, $app->run(['make:controller', 'User']));
            self::assertSame(0, $app->run(['make:model', 'User']));
            self::assertSame(1, $app->run(['make:model', '..']));
            self::assertSame(1, $app->run(['make:model', 'User']));
            self::assertStringContainsString(
                'class UserController',
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'UserController.php'),
            );
            self::assertStringContainsString(
                'namespace App\\Models;',
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php'),
            );
            token_get_all(
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'UserController.php'),
                TOKEN_PARSE,
            );
            token_get_all(
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php'),
                TOKEN_PARSE,
            );
        } finally {
            fclose($output);
            $this->removeTree($workspace);
        }
    }

    public function testMigrateRequiresConfiguredDatabaseInsteadOfPretendingSuccess(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        $previousDsn = getenv('NORA_DSN');
        putenv('NORA_DSN');
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(1, $app->run(['migrate']));
        } finally {
            if ($previousDsn !== false) {
                putenv('NORA_DSN=' . $previousDsn);
            }
            fclose($output);
            rmdir($workspace);
        }
    }

    public function testServeRejectsInvalidHostWithoutStartingAProcess(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(1, $app->run(['serve', 'not-an-ip', '8000']));
        } finally {
            fclose($output);
            rmdir($workspace);
        }
    }

    /**
     * @return array{ConsoleApplication, resource}
     */
    private function application(string $workspace): array
    {
        $output = fopen('php://memory', 'w+b');
        $error = fopen('php://memory', 'w+b');
        self::assertIsResource($output);
        self::assertIsResource($error);

        return [
            new ConsoleApplication(dirname(__DIR__), $workspace, $output, $error),
            $output,
        ];
    }

    private function removeTree(string $directory): void
    {
        $entries = scandir($directory);
        self::assertNotFalse($entries);
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
