<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gember\DependencyContracts\EventStore\Rdbms\OptimisticLockException;
use Gember\DependencyContracts\EventStore\Rdbms\RdbmsEvent;
use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventFactory;
use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\TableSchema\TableSchemaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Override;
use DateTimeImmutable;

/**
 * @internal
 */
final class DoctrineDbalRdbmsEventStoreRepositoryTest extends TestCase
{
    private Connection $connection;
    private DoctrineDbalRdbmsEventStoreRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite:///:memory:'));
        $this->connection->executeStatement((string) file_get_contents(__DIR__ . '/schema.sql'));

        $connection = $this->connection;

        $this->repository = new DoctrineDbalRdbmsEventStoreRepository(
            $connection,
            TableSchemaFactory::createDefaultEventStore(),
            TableSchemaFactory::createDefaultEventStoreRelation(),
            TableSchemaFactory::createDefaultEventStoreLock(),
            new DoctrineDbalRdbmsEventFactory(),
        );

        $this->repository->saveEvents(
            ['6ae07469-0f43-4f33-979b-c783b6824ce0', '0c1ff409-a4be-42f1-90dd-5d7b0130a426'],
            ['event_name', 'event_name_2', 'event_name_3'],
            null,
            [
                new RdbmsEvent(
                    '63129dc3-4a27-4242-a8bc-6f79636a6fa9',
                    ['6ae07469-0f43-4f33-979b-c783b6824ce0', '0c1ff409-a4be-42f1-90dd-5d7b0130a426'],
                    'event_name',
                    '{"data":"some"}',
                    ['metadata' => 'some'],
                    new DateTimeImmutable('2024-12-06 12:05:04.456344'),
                ),
                new RdbmsEvent(
                    '707678d3-c91d-4864-9729-555b22496853',
                    ['0e76f2bd-2aae-44a4-b149-740c080e4d05'],
                    'event_name',
                    '{"data":"another_event"}',
                    ['metadata' => 'another_event'],
                    new DateTimeImmutable('2024-12-01 13:16:24.467784'),
                ),
                new RdbmsEvent(
                    '7ac51abe-9176-4794-8246-24b75c2ba914',
                    ['0c1ff409-a4be-42f1-90dd-5d7b0130a426'],
                    'event_name_2',
                    '{"data":"another"}',
                    ['metadata' => 'another'],
                    new DateTimeImmutable('2024-12-04 13:15:26.755844'),
                ),
                new RdbmsEvent(
                    'd404e3c1-c782-4115-b8ec-d8cb341d87cb',
                    ['6ae07469-0f43-4f33-979b-c783b6824ce0'],
                    'event_name_3',
                    '{"data":"another"}',
                    ['metadata' => 'another3'],
                    new DateTimeImmutable('2024-12-02 13:16:24.467784'),
                ),
            ],
        );
    }

    #[Test]
    public function itShouldGetEvents(): void
    {
        $events = $this->repository->getEvents(
            [
                '0c1ff409-a4be-42f1-90dd-5d7b0130a426',
                '6ae07469-0f43-4f33-979b-c783b6824ce0',
            ],
            [
                'event_name',
                'event_name_2',
            ],
        );

        self::assertEquals([
            new RdbmsEvent(
                '7ac51abe-9176-4794-8246-24b75c2ba914',
                [
                    '0c1ff409-a4be-42f1-90dd-5d7b0130a426',
                ],
                'event_name_2',
                '{"data":"another"}',
                ['metadata' => 'another'],
                new DateTimeImmutable('2024-12-04 13:15:26.755844'),
            ),
            new RdbmsEvent(
                '63129dc3-4a27-4242-a8bc-6f79636a6fa9',
                [
                    '6ae07469-0f43-4f33-979b-c783b6824ce0',
                    '0c1ff409-a4be-42f1-90dd-5d7b0130a426',
                ],
                'event_name',
                '{"data":"some"}',
                ['metadata' => 'some'],
                new DateTimeImmutable('2024-12-06 12:05:04.456344'),
            ),
        ], $events);
    }

    #[Test]
    public function itShouldSaveEventsWhenNoExistingEvents(): void
    {
        $this->repository->saveEvents(
            ['new-domain-tag'],
            ['new_event_name'],
            null,
            [
                new RdbmsEvent(
                    'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                    ['new-domain-tag'],
                    'new_event_name',
                    '{"data":"new"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $events = $this->repository->getEvents(['new-domain-tag'], ['new_event_name']);

        self::assertCount(1, $events);
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $events[0]->eventId);
    }

    #[Test]
    public function itShouldSaveEventsWhenLastEventIdMatches(): void
    {
        $this->repository->saveEvents(
            ['0c1ff409-a4be-42f1-90dd-5d7b0130a426', '6ae07469-0f43-4f33-979b-c783b6824ce0'],
            ['event_name', 'event_name_2'],
            '63129dc3-4a27-4242-a8bc-6f79636a6fa9',
            [
                new RdbmsEvent(
                    'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
                    ['0c1ff409-a4be-42f1-90dd-5d7b0130a426'],
                    'event_name',
                    '{"data":"appended"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $events = $this->repository->getEvents(
            ['0c1ff409-a4be-42f1-90dd-5d7b0130a426'],
            ['event_name', 'event_name_2'],
        );

        self::assertCount(3, $events);
    }

    #[Test]
    public function itShouldThrowOptimisticLockExceptionWhenLastEventIdDoesNotMatch(): void
    {
        self::expectException(OptimisticLockException::class);

        $this->repository->saveEvents(
            ['0c1ff409-a4be-42f1-90dd-5d7b0130a426', '6ae07469-0f43-4f33-979b-c783b6824ce0'],
            ['event_name', 'event_name_2'],
            'wrong-event-id',
            [
                new RdbmsEvent(
                    'cccccccc-dddd-eeee-ffff-000000000000',
                    ['0c1ff409-a4be-42f1-90dd-5d7b0130a426'],
                    'event_name',
                    '{"data":"should_not_be_saved"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );
    }

    #[Test]
    public function itShouldNotSaveEventsWhenOptimisticLockFails(): void
    {
        try {
            $this->repository->saveEvents(
                ['0c1ff409-a4be-42f1-90dd-5d7b0130a426', '6ae07469-0f43-4f33-979b-c783b6824ce0'],
                ['event_name', 'event_name_2'],
                'wrong-event-id',
                [
                    new RdbmsEvent(
                        'dddddddd-eeee-ffff-0000-111111111111',
                        ['0c1ff409-a4be-42f1-90dd-5d7b0130a426'],
                        'event_name',
                        '{"data":"should_not_be_saved"}',
                        [],
                        new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                    ),
                ],
            );
        } catch (OptimisticLockException) {
            // Expected
        }

        $events = $this->repository->getEvents(
            ['0c1ff409-a4be-42f1-90dd-5d7b0130a426'],
            ['event_name', 'event_name_2'],
        );

        self::assertCount(2, $events);
    }

    #[Test]
    public function itShouldCreateLockRowOnFirstSave(): void
    {
        $this->repository->saveEvents(
            ['brand-new-tag'],
            ['brand_new_event'],
            null,
            [
                new RdbmsEvent(
                    '11111111-1111-1111-1111-111111111111',
                    ['brand-new-tag'],
                    'brand_new_event',
                    '{"data":"first"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $lockRows = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        self::assertNotEmpty($lockRows);
    }

    #[Test]
    public function itShouldReuseLockRowOnSubsequentSavesToSameBoundary(): void
    {
        $this->repository->saveEvents(
            ['reuse-tag'],
            ['reuse_event'],
            null,
            [
                new RdbmsEvent(
                    '22222222-2222-2222-2222-222222222222',
                    ['reuse-tag'],
                    'reuse_event',
                    '{"data":"first"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $lockRowsAfterFirst = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        $this->repository->saveEvents(
            ['reuse-tag'],
            ['reuse_event'],
            '22222222-2222-2222-2222-222222222222',
            [
                new RdbmsEvent(
                    '33333333-3333-3333-3333-333333333333',
                    ['reuse-tag'],
                    'reuse_event',
                    '{"data":"second"}',
                    [],
                    new DateTimeImmutable('2025-01-02 00:00:00.000000'),
                ),
            ],
        );

        $lockRowsAfterSecond = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        self::assertCount(count($lockRowsAfterFirst), $lockRowsAfterSecond);
    }

    #[Test]
    public function itShouldCreateSeparateLockRowsForDifferentBoundaries(): void
    {
        $lockRowsBefore = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        $this->repository->saveEvents(
            ['boundary-a'],
            ['event_a'],
            null,
            [
                new RdbmsEvent(
                    '44444444-4444-4444-4444-444444444444',
                    ['boundary-a'],
                    'event_a',
                    '{"data":"a"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $this->repository->saveEvents(
            ['boundary-b'],
            ['event_b'],
            null,
            [
                new RdbmsEvent(
                    '55555555-5555-5555-5555-555555555555',
                    ['boundary-b'],
                    'event_b',
                    '{"data":"b"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $lockRowsAfter = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        self::assertCount(count($lockRowsBefore) + 2, $lockRowsAfter);
    }

    #[Test]
    public function itShouldProduceSameLockRowRegardlessOfDomainTagOrder(): void
    {
        $this->repository->saveEvents(
            ['tag-z', 'tag-a'],
            ['order_event'],
            null,
            [
                new RdbmsEvent(
                    '66666666-6666-6666-6666-666666666666',
                    ['tag-z', 'tag-a'],
                    'order_event',
                    '{"data":"first"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $lockRowsBefore = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        // Save with reversed domain tag order — should reuse the same lock row
        $this->repository->saveEvents(
            ['tag-a', 'tag-z'],
            ['order_event'],
            '66666666-6666-6666-6666-666666666666',
            [
                new RdbmsEvent(
                    '77777777-7777-7777-7777-777777777777',
                    ['tag-a', 'tag-z'],
                    'order_event',
                    '{"data":"second"}',
                    [],
                    new DateTimeImmutable('2025-01-02 00:00:00.000000'),
                ),
            ],
        );

        $lockRowsAfter = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        self::assertCount(count($lockRowsBefore), $lockRowsAfter);
    }

    #[Test]
    public function itShouldProduceSameLockRowRegardlessOfEventNameOrder(): void
    {
        $this->repository->saveEvents(
            ['order-tag'],
            ['event_z', 'event_a'],
            null,
            [
                new RdbmsEvent(
                    '88888888-8888-8888-8888-888888888888',
                    ['order-tag'],
                    'event_z',
                    '{"data":"first"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $lockRowsBefore = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        // Save with reversed event name order — should reuse the same lock row
        $this->repository->saveEvents(
            ['order-tag'],
            ['event_a', 'event_z'],
            '88888888-8888-8888-8888-888888888888',
            [
                new RdbmsEvent(
                    '99999999-9999-9999-9999-999999999999',
                    ['order-tag'],
                    'event_a',
                    '{"data":"second"}',
                    [],
                    new DateTimeImmutable('2025-01-02 00:00:00.000000'),
                ),
            ],
        );

        $lockRowsAfter = $this->connection->fetchAllAssociative('SELECT * FROM event_store_lock');

        self::assertCount(count($lockRowsBefore), $lockRowsAfter);
    }

    #[Test]
    public function itShouldAllowSequentialSavesToSameBoundaryWithCorrectLastEventId(): void
    {
        $this->repository->saveEvents(
            ['sequential-tag'],
            ['sequential_event'],
            null,
            [
                new RdbmsEvent(
                    'aaa11111-1111-1111-1111-111111111111',
                    ['sequential-tag'],
                    'sequential_event',
                    '{"data":"first"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $this->repository->saveEvents(
            ['sequential-tag'],
            ['sequential_event'],
            'aaa11111-1111-1111-1111-111111111111',
            [
                new RdbmsEvent(
                    'aaa22222-2222-2222-2222-222222222222',
                    ['sequential-tag'],
                    'sequential_event',
                    '{"data":"second"}',
                    [],
                    new DateTimeImmutable('2025-01-02 00:00:00.000000'),
                ),
            ],
        );

        $this->repository->saveEvents(
            ['sequential-tag'],
            ['sequential_event'],
            'aaa22222-2222-2222-2222-222222222222',
            [
                new RdbmsEvent(
                    'aaa33333-3333-3333-3333-333333333333',
                    ['sequential-tag'],
                    'sequential_event',
                    '{"data":"third"}',
                    [],
                    new DateTimeImmutable('2025-01-03 00:00:00.000000'),
                ),
            ],
        );

        $events = $this->repository->getEvents(['sequential-tag'], ['sequential_event']);

        self::assertCount(3, $events);
    }

    #[Test]
    public function itShouldThrowOptimisticLockExceptionOnSecondSaveWithStaleLastEventId(): void
    {
        $this->repository->saveEvents(
            ['stale-tag'],
            ['stale_event'],
            null,
            [
                new RdbmsEvent(
                    'bbb11111-1111-1111-1111-111111111111',
                    ['stale-tag'],
                    'stale_event',
                    '{"data":"first"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        // Second save succeeds, advancing the last event ID
        $this->repository->saveEvents(
            ['stale-tag'],
            ['stale_event'],
            'bbb11111-1111-1111-1111-111111111111',
            [
                new RdbmsEvent(
                    'bbb22222-2222-2222-2222-222222222222',
                    ['stale-tag'],
                    'stale_event',
                    '{"data":"second"}',
                    [],
                    new DateTimeImmutable('2025-01-02 00:00:00.000000'),
                ),
            ],
        );

        // Third save uses the OLD last event ID — should fail
        self::expectException(OptimisticLockException::class);

        $this->repository->saveEvents(
            ['stale-tag'],
            ['stale_event'],
            'bbb11111-1111-1111-1111-111111111111',
            [
                new RdbmsEvent(
                    'bbb33333-3333-3333-3333-333333333333',
                    ['stale-tag'],
                    'stale_event',
                    '{"data":"should_not_be_saved"}',
                    [],
                    new DateTimeImmutable('2025-01-03 00:00:00.000000'),
                ),
            ],
        );
    }

    #[Test]
    public function itShouldGetEventsAfterEventId(): void
    {
        $events = $this->repository->getEvents(
            [
                '0c1ff409-a4be-42f1-90dd-5d7b0130a426',
                '6ae07469-0f43-4f33-979b-c783b6824ce0',
            ],
            [
                'event_name',
                'event_name_2',
            ],
            '7ac51abe-9176-4794-8246-24b75c2ba914',
        );

        self::assertEquals([
            new RdbmsEvent(
                '63129dc3-4a27-4242-a8bc-6f79636a6fa9',
                [
                    '6ae07469-0f43-4f33-979b-c783b6824ce0',
                    '0c1ff409-a4be-42f1-90dd-5d7b0130a426',
                ],
                'event_name',
                '{"data":"some"}',
                ['metadata' => 'some'],
                new DateTimeImmutable('2024-12-06 12:05:04.456344'),
            ),
        ], $events);
    }

    #[Test]
    public function itShouldNotAffectDifferentBoundaryWhenOptimisticLockFailsOnAnother(): void
    {
        // Save to boundary A
        $this->repository->saveEvents(
            ['isolation-a'],
            ['isolation_event'],
            null,
            [
                new RdbmsEvent(
                    'ccc11111-1111-1111-1111-111111111111',
                    ['isolation-a'],
                    'isolation_event',
                    '{"data":"a"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        // Fail on boundary A with wrong last event ID
        try {
            $this->repository->saveEvents(
                ['isolation-a'],
                ['isolation_event'],
                'wrong-id',
                [
                    new RdbmsEvent(
                        'ccc22222-2222-2222-2222-222222222222',
                        ['isolation-a'],
                        'isolation_event',
                        '{"data":"should_fail"}',
                        [],
                        new DateTimeImmutable('2025-01-02 00:00:00.000000'),
                    ),
                ],
            );
        } catch (OptimisticLockException) {
            // Expected
        }

        // Save to boundary B should succeed independently
        $this->repository->saveEvents(
            ['isolation-b'],
            ['isolation_event'],
            null,
            [
                new RdbmsEvent(
                    'ccc33333-3333-3333-3333-333333333333',
                    ['isolation-b'],
                    'isolation_event',
                    '{"data":"b"}',
                    [],
                    new DateTimeImmutable('2025-01-01 00:00:00.000000'),
                ),
            ],
        );

        $eventsB = $this->repository->getEvents(['isolation-b'], ['isolation_event']);

        self::assertCount(1, $eventsB);
    }
}
