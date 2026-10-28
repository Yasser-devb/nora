<?php

declare(strict_types=1);

namespace Nora\Tests\Integration;

use Nora\Database\MigrationRunner;
use Nora\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class DatabaseServerTest extends TestCase
{
    public function testParameterizedWritesCommitAndRollBackAgainstMySql(): void
    {
        $connection = $this->connection();
        $table = 'nora_test_writes_' . bin2hex(random_bytes(6));

        try {
            $connection->pdo()->exec(
                'CREATE TABLE `' . $table . '` (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(100) NOT NULL)',
            );

            self::assertSame(
                1,
                $connection->execute(
                    'INSERT INTO `' . $table . '` (value) VALUES (?)',
                    ["committed-' OR 1=1 --"],
                ),
            );

            $connection->transaction(static function (PdoConnection $connection) use ($table): void {
                $connection->execute(
                    'INSERT INTO `' . $table . '` (value) VALUES (?)',
                    ['rolled-back'],
                );
            });

            try {
                $connection->transaction(static function (PdoConnection $connection) use ($table): void {
                    $connection->execute(
                        'INSERT INTO `' . $table . '` (value) VALUES (?)',
                        ['must-not-persist'],
                    );
                    throw new RuntimeException('Force rollback of a written row.');
                });
                self::fail('The transaction callback should propagate its exception.');
            } catch (RuntimeException $exception) {
                self::assertSame('Force rollback of a written row.', $exception->getMessage());
            }

            self::assertSame(
                ['value' => "committed-' OR 1=1 --"],
                $connection->fetchOne(
                    'SELECT value FROM `' . $table . '` WHERE value = ?',
                    ["committed-' OR 1=1 --"],
                ),
            );
            self::assertSame(
                [
                    ['value' => "committed-' OR 1=1 --"],
                    ['value' => 'rolled-back'],
                ],
                $connection->fetchAll('SELECT value FROM `' . $table . '` ORDER BY id'),
            );
            self::assertFalse($connection->pdo()->inTransaction());
        } finally {
            $connection->pdo()->exec('DROP TABLE IF EXISTS `' . $table . '`');
            self::assertSame(
                0,
                (int) $connection->pdo()->query(
                    'SELECT COUNT(*) FROM information_schema.tables'
                    . ' WHERE table_schema = DATABASE() AND table_name = ' . $connection->pdo()->quote($table),
                )->fetchColumn(),
                'The test-owned write table should be removed after the test.',
            );
        }
    }

    public function testMigrationStatusRepeatedRunsAndFailureHistoryAgainstMySql(): void
    {
        $connection = $this->connection();
        $pdo = $connection->pdo();
        $suffix = bin2hex(random_bytes(6));
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-migrations-' . $suffix;
        $successTable = 'nora_test_migration_success_' . $suffix;
        $failureTable = 'nora_test_migration_failure_' . $suffix;
        $successName = gmdate('YmdHis') . '_nora_test_success_' . $suffix;
        $failureName = gmdate('YmdHis') . '_nora_test_failure_' . $suffix;
        $successPath = $directory . DIRECTORY_SEPARATOR . $successName . '.php';
        $failurePath = $directory . DIRECTORY_SEPARATOR . $failureName . '.php';

        if (!mkdir($directory)) {
            self::fail('Unable to create the temporary migration fixture directory.');
        }

        try {
            $this->writeMigration(
                $successPath,
                $this->migrationSource($successTable),
            );
            $runner = new MigrationRunner($pdo, $directory);

            self::assertSame(
                [['migration' => $successName, 'applied' => false, 'batch' => null]],
                $runner->status(),
            );
            self::assertSame([$successName], $runner->migrate());
            $appliedStatus = $runner->status();
            self::assertCount(1, $appliedStatus);
            self::assertSame($successName, $appliedStatus[0]['migration']);
            self::assertTrue($appliedStatus[0]['applied']);
            $successBatch = $appliedStatus[0]['batch'];
            self::assertIsInt($successBatch);
            self::assertGreaterThan(0, $successBatch);
            self::assertSame([], $runner->migrate());

            $this->writeMigration(
                $failurePath,
                $this->migrationSource($failureTable, true),
            );

            try {
                $runner->migrate();
                self::fail('The failing migration should propagate its exception.');
            } catch (RuntimeException $exception) {
                self::assertSame('Intentional integration migration failure.', $exception->getMessage());
            }

            self::assertSame(
                [
                    ['migration' => $successName, 'applied' => true, 'batch' => $successBatch],
                    ['migration' => $failureName, 'applied' => false, 'batch' => null],
                ],
                $runner->status(),
            );
            self::assertSame(
                1,
                (int) $pdo->query(
                    'SELECT COUNT(*) FROM information_schema.tables'
                    . ' WHERE table_schema = DATABASE() AND table_name = ' . $pdo->quote($failureTable),
                )->fetchColumn(),
                'MySQL DDL may persist even when the migration subsequently fails.',
            );
            self::assertSame(
                0,
                (int) $pdo->query(
                    'SELECT COUNT(*) FROM nora_migrations WHERE migration = ' . $pdo->quote($failureName),
                )->fetchColumn(),
            );
        } finally {
            $this->cleanupMigrationFixtures(
                $pdo,
                $directory,
                [$successPath, $failurePath],
                [$successTable, $failureTable],
                [$successName, $failureName],
            );
        }
    }

    private function connection(): PdoConnection
    {
        $dsn = getenv('NORA_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            if (getenv('NORA_REQUIRE_TEST_DB') === '1') {
                self::fail('NORA_TEST_DSN is required when NORA_REQUIRE_TEST_DB=1.');
            }

            self::markTestSkipped('Set NORA_TEST_DSN to a dedicated nora_test database for MySQL integration tests.');
        }
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            self::fail('NORA_TEST_DSN must use the MySQL PDO driver.');
        }

        $parts = [];
        foreach (explode(';', substr($dsn, strlen('mysql:'))) as $part) {
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $parts[strtolower($pair[0])] = $pair[1];
            }
        }

        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            self::fail('Database integration tests may connect only to a loopback MySQL host.');
        }

        $database = $parts['dbname'] ?? '';
        $configuredDatabase = getenv('NORA_TEST_DB_NAME');
        if (
            $database === ''
            || $configuredDatabase === false
            || $configuredDatabase !== $database
            || preg_match('/^nora_test(?:_[a-z0-9]+)*$/D', $database) !== 1
        ) {
            self::fail(
                'Database integration tests require a matching NORA_TEST_DB_NAME and a database named nora_test or nora_test_<suffix>.',
            );
        }

        $username = getenv('NORA_TEST_DB_USERNAME');
        $password = getenv('NORA_TEST_DB_PASSWORD');
        if ($username === false || $username === '' || $password === false || $password === '') {
            self::fail('Set explicit least-privilege NORA_TEST_DB_USERNAME and NORA_TEST_DB_PASSWORD credentials.');
        }

        return PdoConnection::connect($dsn, $username, $password);
    }

    private function migrationSource(string $table, bool $failAfterCreate = false): string
    {
        $afterCreate = $failAfterCreate
            ? "throw new \\RuntimeException('Intentional integration migration failure.');"
            : '';

        return '<?php return new class implements \\Nora\\Database\\MigrationInterface {'
            . ' public function up(\\PDO $pdo): void {'
            . ' $pdo->exec(\'CREATE TABLE `' . $table . '` (id INT PRIMARY KEY)\');'
            . ' ' . $afterCreate
            . ' }'
            . ' public function down(\\PDO $pdo): void {'
            . ' $pdo->exec(\'DROP TABLE IF EXISTS `' . $table . '` \');'
            . ' }'
            . '};';
    }

    private function writeMigration(string $path, string $source): void
    {
        if (file_put_contents($path, $source) === false) {
            self::fail('Unable to write a temporary migration fixture.');
        }
    }

    /**
     * @param list<string> $paths
     * @param list<string> $tables
     * @param list<string> $migrationNames
     */
    private function cleanupMigrationFixtures(
        PDO $pdo,
        string $directory,
        array $paths,
        array $tables,
        array $migrationNames,
    ): void {
        $errors = [];
        foreach ($paths as $path) {
            try {
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('Unable to remove a migration fixture file.');
                }
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        try {
            if (is_dir($directory) && !rmdir($directory)) {
                throw new RuntimeException('Unable to remove the migration fixture directory.');
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        foreach ($tables as $table) {
            try {
                $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        try {
            $tableNames = implode(', ', array_map($pdo->quote(...), $tables));
            $remainingTables = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE() AND table_name IN (' . $tableNames . ')',
            )->fetchColumn();
            if ($remainingTables !== 0) {
                throw new RuntimeException('Test-owned migration tables remain after cleanup.');
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        try {
            $historyExists = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables"
                . " WHERE table_schema = DATABASE() AND table_name = 'nora_migrations'",
            )->fetchColumn() === 1;
            if ($historyExists) {
                $statement = $pdo->prepare(
                    'DELETE FROM nora_migrations WHERE migration IN (:success, :failure)',
                );
                if ($statement === false) {
                    throw new RuntimeException('Unable to prepare migration fixture history cleanup.');
                }
                $statement->execute(['success' => $migrationNames[0], 'failure' => $migrationNames[1]]);
                $remainingHistory = $pdo->prepare(
                    'SELECT COUNT(*) FROM nora_migrations WHERE migration IN (:success, :failure)',
                );
                if ($remainingHistory === false) {
                    throw new RuntimeException('Unable to prepare migration fixture history verification.');
                }
                $remainingHistory->execute([
                    'success' => $migrationNames[0],
                    'failure' => $migrationNames[1],
                ]);
                if ((int) $remainingHistory->fetchColumn() !== 0) {
                    throw new RuntimeException('Test-owned migration history remains after cleanup.');
                }
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        if ($errors !== []) {
            throw new RuntimeException('Database integration fixture cleanup failed: ' . implode(' | ', $errors));
        }
    }
}
