<?php

declare(strict_types=1);

$repositoryRoot = dirname(__DIR__, 2);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-package-check-' . bin2hex(random_bytes(8));
$archiveDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'archive';
$consumerDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'consumer';
$composerHome = $temporaryRoot . DIRECTORY_SEPARATOR . 'composer-home';

function assertPackageCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param list<string> $command
 * @param array<string, string>|false $environment
 */
function runPackageCheckCommand(array $command, string $workingDirectory, array|false $environment): string
{
    $commandLine = implode(' ', array_map(escapeshellarg(...), $command));
    $process = proc_open(
        $commandLine,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . $command[0]);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($stdout !== false && $stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($stderr !== false && $stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            'Command failed with exit code %d: %s',
            $exitCode,
            implode(' ', $command),
        ));
    }

    return $stdout === false ? '' : $stdout;
}

function removePackageCheckDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            if (!rmdir($path)) {
                throw new RuntimeException('Unable to remove temporary package-check directory: ' . $path);
            }
        } elseif (!unlink($path)) {
            throw new RuntimeException('Unable to remove temporary package-check file: ' . $path);
        }
    }
    if (!rmdir($directory)) {
        throw new RuntimeException('Unable to remove temporary package-check root.');
    }
}

function fileUri(string $path): string
{
    $path = realpath($path);
    assertPackageCheck($path !== false, 'Unable to resolve the package archive path.');
    $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
    if (PHP_OS_FAMILY === 'Windows') {
        $path = '/' . ltrim($path, '/');
    }

    return 'file://' . str_replace(' ', '%20', $path);
}

try {
    foreach ([$temporaryRoot, $archiveDirectory, $consumerDirectory, $composerHome] as $directory) {
        assertPackageCheck(mkdir($directory), 'Unable to create a temporary package-check directory.');
    }

    $environment = getenv();
    if ($environment === false) {
        $environment = [];
    }
    $environment['COMPOSER_HOME'] = $composerHome;
    $environment['COMPOSER_DISABLE_NETWORK'] = '1';

    $composer = getenv('COMPOSER_BINARY');
    if ($composer === false || $composer === '') {
        $composer = 'composer';
        if (PHP_OS_FAMILY === 'Windows') {
            $composerLocations = runPackageCheckCommand(
                ['where.exe', 'composer.bat'],
                $repositoryRoot,
                $environment,
            );
            $composer = trim(strtok($composerLocations, "\r\n") ?: '');
            assertPackageCheck($composer !== '', 'Unable to locate composer.bat on PATH.');
        }
    }
    runPackageCheckCommand(
        [$composer, 'archive', '--format=zip', '--dir=' . $archiveDirectory],
        $repositoryRoot,
        $environment,
    );

    $archives = glob($archiveDirectory . DIRECTORY_SEPARATOR . '*.zip');
    assertPackageCheck(is_array($archives) && count($archives) === 1, 'Expected exactly one Composer ZIP archive.');
    $archivePath = $archives[0];
    $zip = new ZipArchive();
    assertPackageCheck($zip->open($archivePath) === true, 'Unable to open the Composer package archive.');

    try {
        $manifestEntry = null;
        $relativeFiles = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);
            assertPackageCheck(is_string($entry), 'Unable to read a ZIP entry name.');
            $relativePath = trim($entry, '/');
            if ($relativePath === '') {
                continue;
            }
            $relativeFiles[] = $relativePath;
            if ($relativePath === 'composer.json') {
                $manifestEntry = $entry;
            }
        }

        assertPackageCheck($manifestEntry !== null, 'Archive is missing composer.json.');
        $requiredFiles = [
            'composer.json',
            'LICENSE',
            'README.md',
            '.env.example',
            'bin/nora',
            'src/Core/ApplicationBootstrap.php',
            'src/Http/Request.php',
            'resources/reactive/runtime.js',
        ];
        foreach ($requiredFiles as $requiredFile) {
            assertPackageCheck(
                in_array($requiredFile, $relativeFiles, true),
                'Composer archive is missing required file: ' . $requiredFile,
            );
        }

        $excludedPath = '~(^|/)(tests|examples|extensions|vendor|node_modules|\.github|\.vscode|coverage|playwright-report|test-results|\.phpunit\.cache)(/|$)~';
        $excludedFiles = '~(^|/)(composer\.lock|package\.json|package-lock\.json|phpunit\.xml)$|(^|/)\.env(?:\..*)?$|(?:\.vsix|\.phar|\.zip|\.tar|\.tar\.gz)$~i';
        foreach ($relativeFiles as $relativeFile) {
            assertPackageCheck(
                preg_match($excludedPath, $relativeFile) !== 1,
                'Development-only path was included in the Composer archive: ' . $relativeFile,
            );
            $isSafeEnvironmentExample = $relativeFile === '.env.example';
            assertPackageCheck(
                $isSafeEnvironmentExample || preg_match($excludedFiles, $relativeFile) !== 1,
                'Development-only or generated file was included in the Composer archive: ' . $relativeFile,
            );
        }
        $manifestContents = $zip->getFromName($manifestEntry);
        assertPackageCheck(is_string($manifestContents), 'Unable to read the archived Composer manifest.');
        $packageManifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
        assertPackageCheck(
            is_array($packageManifest) && ($packageManifest['name'] ?? null) === 'morave/nora',
            'The archived manifest does not describe morave/nora.',
        );
    } finally {
        $zip->close();
    }

    $package = [
        'name' => $packageManifest['name'],
        'version' => '0.0.0',
        'type' => $packageManifest['type'] ?? 'library',
        'license' => $packageManifest['license'] ?? null,
        'require' => $packageManifest['require'] ?? [],
        'autoload' => $packageManifest['autoload'] ?? [],
        'bin' => $packageManifest['bin'] ?? [],
        'dist' => ['type' => 'zip', 'url' => fileUri($archivePath)],
    ];
    if (isset($packageManifest['description'])) {
        $package['description'] = $packageManifest['description'];
    }

    $consumerManifest = [
        'name' => 'nora/package-archive-check',
        'repositories' => [
            ['packagist.org' => false],
            ['type' => 'package', 'package' => $package],
        ],
        'require' => ['morave/nora' => '0.0.0'],
        'config' => ['allow-plugins' => new stdClass()],
    ];
    assertPackageCheck(
        file_put_contents(
            $consumerDirectory . DIRECTORY_SEPARATOR . 'composer.json',
            json_encode($consumerManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ) !== false,
        'Unable to write the temporary consumer Composer manifest.',
    );
    $applicationDirectory = $consumerDirectory . DIRECTORY_SEPARATOR . 'app';
    assertPackageCheck(mkdir($applicationDirectory), 'Unable to create the temporary consumer application.');
    $routes = <<<'PHP'
<?php
return static function (\Nora\Core\Application $app): void {
    $app->route(
        'GET',
        '/package-health',
        static fn (): \Nora\Http\Response => \Nora\Http\Response::text('composer-archive-ok'),
    );
};
PHP;
    assertPackageCheck(
        file_put_contents($applicationDirectory . DIRECTORY_SEPARATOR . 'routes.php', $routes) !== false,
        'Unable to write the temporary consumer route.',
    );
    $smokeTest = <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
if (!class_exists(\Nora\Core\ApplicationBootstrap::class)) {
    fwrite(STDERR, "Nora PSR-4 autoload failed.\n");
    exit(1);
}
$app = (new \Nora\Core\ApplicationBootstrap())->boot(__DIR__ . '/app');
$response = $app->handle(\Nora\Http\Request::fromUri('GET', '/package-health'));
if ($response->status() !== 200 || $response->body() !== 'composer-archive-ok') {
    fwrite(STDERR, "Packaged bootstrap route returned an unexpected response.\n");
    exit(1);
}
echo "PSR-4 autoload and bootstrapped route passed.\n";
PHP;
    assertPackageCheck(
        file_put_contents($consumerDirectory . DIRECTORY_SEPARATOR . 'verify-consumer.php', $smokeTest) !== false,
        'Unable to write the temporary consumer verification script.',
    );

    runPackageCheckCommand(
        [$composer, 'install', '--no-interaction', '--no-plugins', '--no-scripts', '--no-progress'],
        $consumerDirectory,
        $environment,
    );
    runPackageCheckCommand(
        [PHP_BINARY, $consumerDirectory . DIRECTORY_SEPARATOR . 'verify-consumer.php'],
        $consumerDirectory,
        $environment,
    );

    $installedPackage = $consumerDirectory . DIRECTORY_SEPARATOR . 'vendor'
        . DIRECTORY_SEPARATOR . 'morave' . DIRECTORY_SEPARATOR . 'nora';
    assertPackageCheck(
        is_file($installedPackage . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'reactive'
            . DIRECTORY_SEPARATOR . 'runtime.js'),
        'The installed package is missing the reactive runtime asset.',
    );
    $cliPath = $consumerDirectory . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin'
        . DIRECTORY_SEPARATOR . 'nora';
    if (PHP_OS_FAMILY === 'Windows' && !is_file($cliPath) && is_file($cliPath . '.bat')) {
        $cliPath .= '.bat';
    }
    assertPackageCheck(is_file($cliPath), 'Composer did not install the vendor/bin/nora CLI.');
    if (PHP_OS_FAMILY === 'Windows') {
        $cliOutput = runPackageCheckCommand(
            [PHP_BINARY, $installedPackage . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'nora', '--version'],
            $consumerDirectory,
            $environment,
        );
    } else {
        $cliOutput = runPackageCheckCommand([$cliPath, '--version'], $consumerDirectory, $environment);
    }
    assertPackageCheck(
        str_contains($cliOutput, 'Nora Framework'),
        'The installed vendor/bin/nora CLI returned an unexpected version response.',
    );

    fwrite(STDOUT, "Composer archive contents, clean install, runtime asset, bootstrap, and CLI checks passed.\n");
} finally {
    removePackageCheckDirectory($temporaryRoot);
}
