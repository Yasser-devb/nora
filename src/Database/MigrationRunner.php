<?php

declare(strict_types=1);

namespace Nora\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const TABLE = 'nora_migrations';
    private readonly bool $transactionalMigrations;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->transactionalMigrations = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql';
    }

    /**
     * @return list<array{migration: string, applied: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->ensureRepository();
        $applied = [];
        $statement = $this->pdo->query('SELECT migration, batch FROM ' . self::TABLE);
        if ($statement === false) {
            throw new DatabaseException('Unable to read migration status.');
        }
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $applied[$row['migration']] = (int) $row['batch'];
        }

        $status = [];
        foreach ($this->migrationFiles() as $name => $path) {
            $status[] = [
                'migration' => $name,
                'applied' => array_key_exists($name, $applied),
                'batch' => $applied[$name] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Apply pending migrations and return their identifiers.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $files = $this->migrationFiles();
        $status = $this->status();
        $applied = [];
        foreach ($status as $item) {
            if ($item['applied']) {
                $applied[$item['migration']] = true;
            }
        }

        $batchStatement = $this->pdo->query('SELECT COALESCE(MAX(batch), 0) FROM ' . self::TABLE);
        if ($batchStatement === false) {
            throw new DatabaseException('Unable to determine migration batch.');
        }
        $batch = ((int) $batchStatement->fetchColumn()) + 1;
        $completed = [];

        foreach ($files as $name => $path) {
            if (isset($applied[$name])) {
                continue;
            }

            $migration = $this->loadMigration($path);
            if ($this->transactionalMigrations) {
                $this->pdo->beginTransaction();
            }
            try {
                $migration->up($this->pdo);
                $statement = $this->pdo->prepare(
                    'INSERT INTO ' . self::TABLE . ' (migration, batch) VALUES (:migration, :batch)',
                );
                if ($statement === false) {
                    throw new DatabaseException('Unable to prepare migration status update.');
                }
                $statement->execute(['migration' => $name, 'batch' => $batch]);
                if ($this->transactionalMigrations) {
                    $this->pdo->commit();
                }
                $completed[] = $name;
            } catch (Throwable $exception) {
                if ($this->transactionalMigrations && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        }

        return $completed;
    }

    private function ensureRepository(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE
            . ' (migration VARCHAR(255) PRIMARY KEY, batch INTEGER NOT NULL,'
            . ' executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        );
    }

    /**
     * @return array<string, string>
     */
    private function migrationFiles(): array
    {
        if (!is_dir($this->directory) || !is_readable($this->directory)) {
            throw new RuntimeException('Migration directory is missing or unreadable.');
        }

        $files = glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            throw new RuntimeException('Unable to list migration files.');
        }
        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $path) {
            $name = basename($path, '.php');
            if (preg_match('/^\d{4,}_[a-z0-9_]+$/D', $name) !== 1) {
                throw new RuntimeException('Invalid migration file name: ' . basename($path));
            }
            $migrations[$name] = $path;
        }

        return $migrations;
    }

    private function loadMigration(string $path): MigrationInterface
    {
        $migration = require $path;
        if (!$migration instanceof MigrationInterface) {
            throw new RuntimeException(
                'Migration file must return an instance of ' . MigrationInterface::class . '.',
            );
        }

        return $migration;
    }
}
