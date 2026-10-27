<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Config\Config;
use Nora\Config\ConfigException;
use Nora\Config\EnvironmentLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    public function testReadsDotPathsAndMergesSourcesByPrecedence(): void
    {
        $config = Config::fromSources(
            ['database' => ['host' => 'localhost', 'port' => 3306]],
            ['database' => ['host' => 'db.internal']],
        );

        self::assertSame('db.internal', $config->get('database.host'));
        self::assertSame(3306, $config->get('database.port'));
        self::assertFalse($config->has('database.password'));
    }

    public function testValidatesRequiredConfigurationTypes(): void
    {
        $config = new Config(['app' => ['name' => 'Nora', 'debug' => false]]);
        $config->validate(['app.name' => 'non-empty-string', 'app.debug' => 'boolean']);

        $this->expectException(ConfigException::class);
        $config->validate(['app.name' => 'integer']);
    }

    public function testEnvironmentLoaderParsesQuotedValuesWithoutOverwritingProcessValues(): void
    {
        $name = 'NORA_TEST_' . bin2hex(random_bytes(5));
        $path = tempnam(sys_get_temp_dir(), 'nora-env-');
        self::assertNotFalse($path);
        file_put_contents($path, "# test\n{$name}=\"value with spaces\" # comment\n");
        putenv($name . '=process-value');

        try {
            $values = (new EnvironmentLoader())->load($path);
            self::assertSame('process-value', $values[$name]);
            self::assertSame('process-value', getenv($name));

            $values = (new EnvironmentLoader())->load($path, true);
            self::assertSame('value with spaces', $values[$name]);
        } finally {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            unlink($path);
        }
    }

    public function testEnvironmentLoaderRejectsMalformedAssignments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nora-env-');
        self::assertNotFalse($path);
        file_put_contents($path, "INVALID LINE\n");

        try {
            $this->expectException(ConfigException::class);
            (new EnvironmentLoader())->load($path);
        } finally {
            unlink($path);
        }
    }

    public function testEnvironmentLoaderReportsMissingFile(): void
    {
        $this->expectException(RuntimeException::class);

        (new EnvironmentLoader())->load(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-missing-env-file');
    }
}
