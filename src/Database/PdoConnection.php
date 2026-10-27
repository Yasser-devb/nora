<?php

declare(strict_types=1);

namespace Nora\Database;

use Nora\Logging\LoggerInterface;
use PDO;
use PDOStatement;
use Throwable;

final class PdoConnection
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?LoggerInterface $logger = null,
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
    ): self {
        $pdo = new PDO($dsn, $username, $password, $options);

        return new self($pdo, $logger);
    }

    /**
     * Values must be provided separately from SQL text.
     *
     * @param array<string|int, mixed> $parameters
     */
    public function query(string $sql, array $parameters = []): PDOStatement
    {
        $this->logger?->log('debug', 'Database query executed.', [
            'sql_sha256' => hash('sha256', $sql),
            'sql_length' => strlen($sql),
        ]);
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new DatabaseException('The database could not prepare the query.');
        }

        $statement->execute($parameters);

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
        [$sql, $parameters] = $query->toSql();

        return $this->query($sql, $parameters);
    }

    public function transaction(callable $callback): mixed
    {
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback($this);
            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
