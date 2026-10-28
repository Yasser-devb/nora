<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\MigrationInterface;
use Nora\Database\MigrationRunner;
use Nora\Database\PdoConnection;
use Nora\Database\QueryDiagnostics;
use Nora\Database\QueryBuilder;
use Nora\Logging\LoggerInterface;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class DatabaseTest extends TestCase
{
    public function testQueryBuilderQuotesIdentifiersAndBindsValues(): void
    {
        $query = QueryBuilder::select('users', ['id', 'email'])
            ->where('active', '=', 1)
            ->where('email', 'LIKE', '%@example.test')
            ->limit(20)
            ->offset(10);

        self::assertSame(
            ['SELECT `id`, `email` FROM `users` WHERE `active` = ? AND `email` LIKE ? LIMIT 20 OFFSET 10',
                [1, '%@example.test']],
            $query->toSql(),
        );
    }

    public function testInsertBuilderQuotesIdentifiersAndBindsAllValues(): void
    {
        self::assertSame(
            ['INSERT INTO `users` (`email`, `active`) VALUES (?, ?)', ['user@example.test', true]],
            QueryBuilder::insert('users', ['email' => 'user@example.test', 'active' => true])->toSql(),
        );
    }

    public function testUpdateBuilderBindsAssignmentsBeforeWhereValues(): void
    {
        self::assertSame(
            ['UPDATE `users` SET `active` = ?, `name` = ? WHERE `id` = ?', [false, 'Nora', 17]],
            QueryBuilder::update('users', ['active' => false, 'name' => 'Nora'])
                ->where('id', '=', 17)
                ->toSql(),
        );
    }

    public function testDeleteBuilderBindsWhereValues(): void
    {
        self::assertSame(
            ['DELETE FROM `users` WHERE `id` = ?', [17]],
            QueryBuilder::delete('users')->where('id', '=', 17)->toSql(),
        );
    }

    public function testMutationBuildersRequirePredicatesAndNonEmptyValues(): void
    {
        foreach (
            [
                static fn () => QueryBuilder::insert('users', []),
                static fn () => QueryBuilder::update('users', []),
                static fn () => QueryBuilder::update('users', ['active' => true])->toSql(),
                static fn () => QueryBuilder::delete('users')->toSql(),
            ] as $invalidQuery
        ) {
            try {
                $invalidQuery();
                self::fail('Expected unsafe or empty mutation to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCrudBuilderRejectsUnsafeColumnsAndUnsupportedClauses(): void
    {
        foreach (
            [
                static fn () => QueryBuilder::insert('users', ['email) VALUES (1); --' => 'x']),
                static fn () => QueryBuilder::update('users', ['name' => 'Nora'])
                    ->where('id OR 1=1', '=', 1),
                static fn () => QueryBuilder::delete('users')->limit(1),
                static fn () => QueryBuilder::insert('users', ['name' => 'Nora'])
                    ->where('id', '=', 1),
            ] as $invalidQuery
        ) {
            try {
                $invalidQuery();
                self::fail('Expected unsafe identifier or unsupported clause to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPdoConnectionExecutesCrudBuilderWithSeparatedParameters(): void
    {
        $pdo = new RecordingPDO();
        $connection = new PdoConnection($pdo);
        $affectedRows = $connection->executeBuilder(
            QueryBuilder::update('users', ['email' => 'new@example.test'])->where('id', '=', 42),
        );

        self::assertSame(1, $affectedRows);
        self::assertSame('UPDATE `users` SET `email` = ? WHERE `id` = ?', $pdo->statements[0]->sql);
        self::assertSame(['new@example.test', 42], $pdo->statements[0]->parameters);
    }

    public function testOptInQueryDiagnosticsLogSlowAggregateMetadataWithoutSqlOrBindings(): void
    {
        $records = [];
        $logger = new RecordingLogger($records);
        $clockValues = [1_000, 1_000_001_000];
        $diagnostics = new QueryDiagnostics(
            $logger,
            1_000_000,
            static function () use (&$clockValues): int {
                return array_shift($clockValues) ?? 0;
            },
        );
        $connection = new PdoConnection(new RecordingPDO(), diagnostics: $diagnostics);
        $connection->execute('SELECT ? /* secret marker */', ['private-binding']);

        self::assertCount(1, $records);
        self::assertSame('warning', $records[0][0]);
        self::assertSame('Slow database query.', $records[0][1]);
        self::assertSame('SELECT', $records[0][2]['operation']);
        self::assertSame(1_000_000_000, $records[0][2]['duration_nanoseconds']);
        self::assertSame(hash('sha256', 'SELECT ? /* secret marker */'), $records[0][2]['sql_sha256']);
        self::assertArrayNotHasKey('sql', $records[0][2]);
        self::assertArrayNotHasKey('parameters', $records[0][2]);
        self::assertStringNotContainsString('secret marker', serialize($records));
        self::assertStringNotContainsString('private-binding', serialize($records));
    }

    public function testQueryDiagnosticsOmitQueriesBelowSlowThreshold(): void
    {
        $records = [];
        $diagnostics = new QueryDiagnostics(
            new RecordingLogger($records),
            100,
            static function (): int {
                static $now = 0;

                return $now += 10;
            },
        );
        $connection = new PdoConnection(new RecordingPDO(), diagnostics: $diagnostics);
        $connection->execute('UPDATE users SET active = ?', [true]);

        self::assertSame([], $records);
    }

    public function testQueryDiagnosticsRejectNegativeThreshold(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $records = [];
        new QueryDiagnostics(new RecordingLogger($records), -1);
    }

    public function testQueryBuilderRejectsUnsafeIdentifiersAndOperators(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueryBuilder::select('users; DROP TABLE users');
    }

    public function testQueryBuilderRejectsWildcardConditions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QueryBuilder::select('users')->where('*', '=', 'anything');
    }

    public function testConnectionUsesPreparedStatementsAndKeepsValuesSeparate(): void
    {
        $pdo = new RecordingPDO();
        $connection = new PdoConnection($pdo);
        $connection->execute('SELECT * FROM users WHERE email = ?', ['user@example.test']);

        self::assertSame('SELECT * FROM users WHERE email = ?', $pdo->statements[0]->sql);
        self::assertSame(['user@example.test'], $pdo->statements[0]->parameters);
    }

    public function testTransactionCommitsAndRollsBackOnFailure(): void
    {
        $pdo = new RecordingPDO();
        $connection = new PdoConnection($pdo);
        self::assertSame('result', $connection->transaction(static fn (): string => 'result'));
        self::assertSame(1, $pdo->commits);

        try {
            $connection->transaction(static function (): void {
                throw new RuntimeException('failure');
            });
            self::fail('Expected the transaction callback exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('failure', $exception->getMessage());
        }

        self::assertSame(1, $pdo->rollbacks);
    }

    public function testNestedTransactionUsesSavepointAndRollsBackOnlyNestedWork(): void
    {
        $pdo = new RecordingPDO();
        $connection = new PdoConnection($pdo);

        $result = $connection->transaction(function (PdoConnection $connection) use ($pdo): string {
            try {
                $connection->transaction(static function (): void {
                    throw new RuntimeException('nested failure');
                });
            } catch (RuntimeException $exception) {
                self::assertSame('nested failure', $exception->getMessage());
            }

            return 'outer result';
        });

        self::assertSame('outer result', $result);
        self::assertSame(1, $pdo->commits);
        self::assertSame(0, $pdo->rollbacks);
        self::assertContains('SAVEPOINT nora_sp_2', $pdo->executed);
        self::assertContains('ROLLBACK TO SAVEPOINT nora_sp_2', $pdo->executed);
        self::assertContains('RELEASE SAVEPOINT nora_sp_2', $pdo->executed);
    }

    public function testOriginalTransactionFailureSurvivesRollbackFailureAndFailureIsLogged(): void
    {
        $pdo = new RecordingPDO();
        $pdo->failRollback = true;
        $logged = [];
        $logger = new class($logged) implements LoggerInterface {
            /** @param list<array{string, string, array<string, mixed>}> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log(string $level, string $message, array $context = []): void
            {
                $this->logged[] = [$level, $message, $context];
            }
        };
        $connection = new PdoConnection($pdo, $logger);

        try {
            $connection->transaction(static function (): void {
                throw new RuntimeException('original transaction failure');
            });
            self::fail('Expected the original transaction exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('original transaction failure', $exception->getMessage());
        }

        self::assertSame('error', $logged[0][0]);
        self::assertSame('Database transaction rollback failed.', $logged[0][1]);
        self::assertSame(RuntimeException::class, $logged[0][2]['exception_type']);
    }

    public function testOuterTransactionCannotCommitAfterNestedRollbackFails(): void
    {
        $pdo = new RecordingPDO();
        $pdo->failRollback = true;
        $connection = new PdoConnection($pdo);

        try {
            $connection->transaction(function (PdoConnection $connection): void {
                try {
                    $connection->transaction(static function (): void {
                        throw new RuntimeException('nested original');
                    });
                } catch (RuntimeException) {
                }
            });
            self::fail('A transaction with a failed savepoint rollback must not commit.');
        } catch (\Nora\Database\DatabaseException $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame('rollback failure', $exception->getPrevious()->getMessage());
        }

        self::assertSame(0, $pdo->commits);
    }

    public function testMigrationRunnerAppliesPendingMigrationsAndReportsStatus(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-migrations-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory));
        $file = $directory . DIRECTORY_SEPARATOR . '202610270001_create_widgets.php';
        file_put_contents(
            $file,
            '<?php return new class implements \\Nora\\Database\\MigrationInterface {'
            . ' public function up(\\PDO $pdo): void { $pdo->exec("CREATE TABLE widgets (id INT)"); }'
            . ' public function down(\\PDO $pdo): void { $pdo->exec("DROP TABLE widgets"); }'
            . '};',
        );

        try {
            $pdo = new RecordingPDO('mysql');
            $runner = new MigrationRunner($pdo, $directory);
            self::assertSame(['202610270001_create_widgets'], $runner->migrate());
            self::assertSame([], $runner->migrate());
            self::assertSame(
                [['migration' => '202610270001_create_widgets', 'applied' => true, 'batch' => 1]],
                $runner->status(),
            );
            self::assertContains('CREATE TABLE widgets (id INT)', $pdo->executed);
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function testMigrationRunnerRejectsInvalidMigrationNames(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-migrations-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory));
        $file = $directory . DIRECTORY_SEPARATOR . 'migration.php';
        file_put_contents($file, '<?php return null;');

        try {
            $this->expectException(RuntimeException::class);
            (new MigrationRunner(new RecordingPDO('mysql'), $directory))->status();
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }
}

/**
 * @internal
 */
final class RecordingPDO extends PDO
{
    /** @var list<RecordingPDOStatement> */
    public array $statements = [];

    /** @var list<string> */
    public array $executed = [];

    /** @var array<string, int> */
    public array $appliedMigrations = [];

    public bool $transaction = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    public bool $failRollback = false;

    public function __construct(private readonly string $driver = 'sqlite')
    {
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->driver;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $statement = new RecordingPDOStatement($this, $query);
        $this->statements[] = $statement;

        return $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = new RecordingPDOStatement($this, $query);
        $this->statements[] = $statement;

        return $statement;
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;
        if ($statement === 'ROLLBACK TO SAVEPOINT nora_sp_2' && $this->failRollback) {
            throw new RuntimeException('rollback failure');
        }

        return 0;
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;

        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        $this->commits++;

        return true;
    }

    public function rollBack(): bool
    {
        if ($this->failRollback) {
            throw new RuntimeException('rollback failure');
        }
        $this->transaction = false;
        $this->rollbacks++;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }
}

/**
 * @internal
 */
final class RecordingPDOStatement extends PDOStatement
{
    /** @var array<string|int, mixed> */
    public array $parameters = [];

    public function __construct(
        private readonly RecordingPDO $owner,
        public readonly string $sql,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->parameters = $params ?? [];
        if (str_starts_with($this->sql, 'INSERT INTO nora_migrations')) {
            $this->owner->appliedMigrations[(string) $this->parameters['migration']]
                = (int) $this->parameters['batch'];
        }

        return true;
    }

    public function rowCount(): int
    {
        return 1;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($this->sql === 'SELECT migration, batch FROM nora_migrations') {
            $rows = [];
            foreach ($this->owner->appliedMigrations as $migration => $batch) {
                $rows[] = ['migration' => $migration, 'batch' => $batch];
            }

            return $rows;
        }

        return [];
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->owner->appliedMigrations === []
            ? 0
            : max($this->owner->appliedMigrations);
    }
}

/**
 * @internal
 */
final class RecordingLogger implements LoggerInterface
{
    /**
     * @param list<array{string, string, array<string, mixed>}> $records
     */
    public function __construct(private array &$records)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }
}
