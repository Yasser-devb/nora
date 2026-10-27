<?php

declare(strict_types=1);

namespace Nora\Tests\Integration;

use Nora\Database\PdoConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseServerTest extends TestCase
{
    public function testConfiguredMySqlConnectionBindsValuesAndRollsBackOnFailure(): void
    {
        $dsn = getenv('NORA_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set NORA_TEST_DSN to run integration tests against a MySQL/MariaDB server.');
        }
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            self::fail('NORA_TEST_DSN must use the MySQL PDO driver.');
        }

        $username = getenv('NORA_TEST_DB_USERNAME');
        $password = getenv('NORA_TEST_DB_PASSWORD');
        $connection = PdoConnection::connect(
            $dsn,
            $username === false ? null : $username,
            $password === false ? null : $password,
        );

        self::assertSame(
            ['value' => 'bound-value'],
            $connection->fetchOne('SELECT :value AS value', ['value' => 'bound-value']),
        );

        try {
            $connection->transaction(static function (PdoConnection $connection): void {
                $connection->query('SELECT 1');
                throw new RuntimeException('Force rollback of read-only transaction.');
            });
            self::fail('The transaction callback should propagate its exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Force rollback of read-only transaction.', $exception->getMessage());
        }

        self::assertFalse($connection->pdo()->inTransaction());
    }
}
