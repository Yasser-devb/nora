<?php

declare(strict_types=1);

namespace Nora\Database;

use Nora\Logging\LoggerInterface;
use PDO;
use PDOStatement;
use Throwable;

final class PdoConnection
{
    private int $transactionDepth = 0;

    private ?Throwable $transactionRollbackFailure = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?QueryDiagnostics $diagnostics = null,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function connect(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        ?LoggerInterface $logger = null,
        ?QueryDiagnostics $diagnostics = null,
    ): self {
        $pdo = new PDO($dsn, $username, $password, $options);

        return new self($pdo, $logger, $diagnostics);
    }

    /**
     * Values must be provided separately from SQL text.
     *
     * @param array<string|int, mixed> $parameters
     */
    public function query(string $sql, array $parameters = []): PDOStatement
    {
        $startedAt = $this->diagnostics?->start();
        $this->logger?->log('debug', 'Database query executed.', [
            'sql_sha256' => hash('sha256', $sql),
            'sql_length' => strlen($sql),
        ]);
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new DatabaseException('The database could not prepare the query.');
        }

        $statement->execute($parameters);
        if ($startedAt !== null) {
            $this->diagnostics?->record($sql, $startedAt);
        }

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->query($sql, $parameters)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $parameters
     * @return array<string, mixed>|false
     */
    public function fetchOne(string $sql, array $parameters = []): array|false
    {
        /** @var array<string, mixed>|false */
        return $this->query($sql, $parameters)->fetch();
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    public function execute(string $sql, array $parameters = []): int
    {
        return $this->query($sql, $parameters)->rowCount();
    }

    public function select(QueryBuilder $query): \PDOStatement
    {
        if ($query->operation() !== 'SELECT') {
            throw new DatabaseException('The select method only accepts SELECT queries.');
        }
        [$sql, $parameters] = $query->toSql();

        return $this->query($sql, $parameters);
    }

    public function executeBuilder(QueryBuilder $query): int
    {
        if ($query->operation() === 'SELECT') {
            throw new DatabaseException('Use select for SELECT queries.');
        }
        [$sql, $parameters] = $query->toSql();

        return $this->execute($sql, $parameters);
    }

    public function transaction(callable $callback): mixed
    {
        $startedTransaction = !$this->pdo->inTransaction();
        $savepoint = null;
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
            $this->transactionRollbackFailure = null;
        } else {
            $savepoint = 'nora_sp_' . ($this->transactionDepth + 1);
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        $this->transactionDepth++;
        try {
            $result = $callback($this);
            if ($this->transactionRollbackFailure !== null) {
                throw new DatabaseException(
                    'The transaction cannot commit after a nested rollback failure.',
                    0,
                    $this->transactionRollbackFailure,
                );
            }
            if ($startedTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            try {
                if ($startedTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                } elseif ($savepoint !== null && $this->pdo->inTransaction()) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } elseif ($savepoint !== null) {
                    $rollbackFailure = new DatabaseException(
                        'The database ended the transaction before the nested savepoint could be restored.',
                    );
                    $this->transactionRollbackFailure ??= $rollbackFailure;
                    $this->reportRollbackFailure($rollbackFailure);
                }
            } catch (Throwable $rollbackException) {
                $this->transactionRollbackFailure ??= $rollbackException;
                $this->reportRollbackFailure($rollbackException);
            }

            throw $exception;
        } finally {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                $this->transactionRollbackFailure = null;
            }
        }
    }

    private function reportRollbackFailure(Throwable $exception): void
    {
        try {
            $this->logger?->log('error', 'Database transaction rollback failed.', [
                'exception_type' => $exception::class,
            ]);
        } catch (Throwable $loggingException) {
            error_log(sprintf(
                'Nora database rollback logging failed (%s); rollback failure was %s.',
                $loggingException::class,
                $exception::class,
            ));
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
