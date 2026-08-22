<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\Snapshot;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\DependencyContracts\EventStore\Snapshot\RdbmsSnapshot;
use Gember\RdbmsEventStoreDoctrineDbal\Snapshot\DoctrineDbalRdbmsSnapshotStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\Snapshot\TableSchema\SnapshotTableSchemaFactory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DoctrineDbalRdbmsSnapshotStoreRepositoryTest extends TestCase
{
    private Connection $connection;
    private DoctrineDbalRdbmsSnapshotStoreRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite:///:memory:'));
        $this->connection->executeStatement((string) file_get_contents(__DIR__ . '/../schema.sql'));

        $this->repository = new DoctrineDbalRdbmsSnapshotStoreRepository(
            $this->connection,
            SnapshotTableSchemaFactory::createDefault(),
        );
    }

    #[Test]
    public function itShouldReturnNullWhenNoSnapshotExists(): void
    {
        $result = $this->repository->get(['unknown-tag'], ['unknown_event']);

        self::assertNull($result);
    }

    #[Test]
    public function itShouldSaveAndGetSnapshot(): void
    {
        $snapshot = new RdbmsSnapshot(
            ['domain-tag-1', 'domain-tag-2'],
            ['event_name_1', 'event_name_2'],
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            5,
            '{"state":"some"}',
            new DateTimeImmutable('2025-01-15 10:30:00.123456'),
        );

        $this->repository->save($snapshot);

        $result = $this->repository->get(['domain-tag-1', 'domain-tag-2'], ['event_name_1', 'event_name_2']);

        self::assertNotNull($result);
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result->lastEventId);
        self::assertSame(5, $result->eventCount);
        self::assertSame('{"state":"some"}', $result->payload);
        self::assertEquals(new DateTimeImmutable('2025-01-15 10:30:00.123456'), $result->createdAt);
    }

    #[Test]
    public function itShouldUpdateExistingSnapshot(): void
    {
        $snapshot = new RdbmsSnapshot(
            ['domain-tag-1'],
            ['event_name_1'],
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            3,
            '{"state":"initial"}',
            new DateTimeImmutable('2025-01-15 10:30:00.000000'),
        );

        $this->repository->save($snapshot);

        $updatedSnapshot = new RdbmsSnapshot(
            ['domain-tag-1'],
            ['event_name_1'],
            'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            7,
            '{"state":"updated"}',
            new DateTimeImmutable('2025-01-16 11:00:00.000000'),
        );

        $this->repository->save($updatedSnapshot);

        $result = $this->repository->get(['domain-tag-1'], ['event_name_1']);

        self::assertNotNull($result);
        self::assertSame('bbbbbbbb-cccc-dddd-eeee-ffffffffffff', $result->lastEventId);
        self::assertSame(7, $result->eventCount);
        self::assertSame('{"state":"updated"}', $result->payload);

        $rows = $this->connection->fetchAllAssociative('SELECT * FROM snapshot_store');

        self::assertCount(1, $rows);
    }

    #[Test]
    public function itShouldDistinguishDifferentBoundaries(): void
    {
        $snapshotA = new RdbmsSnapshot(
            ['tag-a'],
            ['event_a'],
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            2,
            '{"state":"a"}',
            new DateTimeImmutable('2025-01-15 10:00:00.000000'),
        );

        $snapshotB = new RdbmsSnapshot(
            ['tag-b'],
            ['event_b'],
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            4,
            '{"state":"b"}',
            new DateTimeImmutable('2025-01-15 11:00:00.000000'),
        );

        $this->repository->save($snapshotA);
        $this->repository->save($snapshotB);

        $resultA = $this->repository->get(['tag-a'], ['event_a']);
        $resultB = $this->repository->get(['tag-b'], ['event_b']);

        self::assertNotNull($resultA);
        self::assertSame('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $resultA->lastEventId);
        self::assertSame('{"state":"a"}', $resultA->payload);

        self::assertNotNull($resultB);
        self::assertSame('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $resultB->lastEventId);
        self::assertSame('{"state":"b"}', $resultB->payload);
    }
}
