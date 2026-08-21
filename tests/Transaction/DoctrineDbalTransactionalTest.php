<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\RdbmsEventStoreDoctrineDbal\Transaction\DoctrineDbalTransactional;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
final class DoctrineDbalTransactionalTest extends TestCase
{
    private Connection $connection;
    private DoctrineDbalTransactional $transactional;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite:///:memory:'));
        $this->connection->executeStatement('CREATE TABLE test_table (id INTEGER PRIMARY KEY, value TEXT)');
        $this->transactional = new DoctrineDbalTransactional($this->connection);
    }

    #[Test]
    public function itShouldCommitOnSuccess(): void
    {
        $this->transactional->transactional(function (): void {
            $this->connection->executeStatement("INSERT INTO test_table (id, value) VALUES (1, 'test')");
        });

        $rows = $this->connection->fetchAllAssociative('SELECT * FROM test_table');

        self::assertCount(1, $rows);
        self::assertSame('test', $rows[0]['value']);
    }

    #[Test]
    public function itShouldRollbackOnFailure(): void
    {
        try {
            $this->transactional->transactional(function (): void {
                $this->connection->executeStatement("INSERT INTO test_table (id, value) VALUES (1, 'test')");

                throw new RuntimeException('something went wrong');
            });
        } catch (RuntimeException) {
            // expected
        }

        $rows = $this->connection->fetchAllAssociative('SELECT * FROM test_table');

        self::assertCount(0, $rows);
    }

    #[Test]
    public function itShouldReturnCallableResult(): void
    {
        $result = $this->transactional->transactional(fn() => 'result-value');

        self::assertSame('result-value', $result);
    }
}
