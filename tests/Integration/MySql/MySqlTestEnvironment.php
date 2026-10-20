<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Integration\MySql;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\DependencyContracts\EventStore\Rdbms\RdbmsEvent;
use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventFactory;
use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\TableSchema\TableSchemaFactory;

/**
 * Shared setup for integration tests against a real MySQL server.
 * Enabled by setting GEMBER_MYSQL_DSN, e.g. pdo-mysql://root:root@127.0.0.1:3306/gember_test.
 */
final class MySqlTestEnvironment
{
    public const string DSN_ENV = 'GEMBER_MYSQL_DSN';

    public static function getDsn(): ?string
    {
        $dsn = getenv(self::DSN_ENV);

        return $dsn === false || $dsn === '' ? null : $dsn;
    }

    public static function createConnection(string $dsn): Connection
    {
        return DriverManager::getConnection((new DsnParser())->parse($dsn));
    }

    public static function createRepository(Connection $connection): DoctrineDbalRdbmsEventStoreRepository
    {
        return new DoctrineDbalRdbmsEventStoreRepository(
            $connection,
            TableSchemaFactory::createDefaultEventStore(),
            TableSchemaFactory::createDefaultEventStoreRelation(),
            TableSchemaFactory::createDefaultEventStoreLock(),
            new DoctrineDbalRdbmsEventFactory(),
        );
    }

    public static function resetSchema(Connection $connection): void
    {
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($connection->createSchemaManager()->listTableNames() as $tableName) {
            $connection->executeStatement(sprintf('DROP TABLE `%s`', $tableName));
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $schema = (string) file_get_contents(__DIR__ . '/../../../resources/schema.sql');

        foreach (array_filter(array_map(trim(...), explode(';', $schema))) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    /**
     * @param list<string> $domainTags
     */
    public static function event(string $eventId, array $domainTags, string $eventName): RdbmsEvent
    {
        return new RdbmsEvent(
            $eventId,
            $domainTags,
            $eventName,
            '{}',
            [],
            new DateTimeImmutable(),
        );
    }
}
