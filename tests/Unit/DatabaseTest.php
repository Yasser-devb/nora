<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\MigrationInterface;
use Nora\Database\MigrationRunner;
use Nora\Database\PdoConnection;
use Nora\Database\QueryBuilder;
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
