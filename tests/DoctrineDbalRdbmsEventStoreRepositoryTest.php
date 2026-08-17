<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test;

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
    private DoctrineDbalRdbmsEventStoreRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite:///:memory:'));
        $connection->executeStatement((string) file_get_contents(__DIR__ . '/schema.sql'));

        $this->repository = new DoctrineDbalRdbmsEventStoreRepository(
            $connection,
            TableSchemaFactory::createDefaultEventStore(),
            TableSchemaFactory::createDefaultEventStoreRelation(),
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
                    '0c1ff409-a4be-42f1-90dd-5d7b0130a426',
                    '6ae07469-0f43-4f33-979b-c783b6824ce0',
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
}
