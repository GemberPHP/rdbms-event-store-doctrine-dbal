<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Gember\EventSourcing\EventStore\Rdbms\RdbmsEvent;
use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventFactory;
use Gember\RdbmsEventStoreDoctrineDbal\DoctrineDbalRdbmsEventStoreRepository;
use Gember\RdbmsEventStoreDoctrineDbal\TableSchema\TableSchemaFactory;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Override;
use DateTimeImmutable;

/**
 * @internal
 */
final class DoctrineDbalRdbmsEventStoreRepositoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&Result $result;
    private DoctrineDbalRdbmsEventStoreRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $connection = Mockery::mock(Connection::class);
        $queryBuilder = Mockery::mock(QueryBuilder::class);

        $this->result = Mockery::mock(Result::class);

        $connection->allows('createQueryBuilder')->andReturn($queryBuilder);
        $queryBuilder->allows('select')->andReturn($queryBuilder);
        $queryBuilder->allows('from')->andReturn($queryBuilder);
        $queryBuilder->allows('join')->andReturn($queryBuilder);
        $queryBuilder->allows('andWhere')->andReturn($queryBuilder);
        $queryBuilder->allows('setParameter')->andReturn($queryBuilder);
        $queryBuilder->allows('orderBy')->andReturn($queryBuilder);
        $queryBuilder->allows('executeQuery')->andReturn($this->result);

        $this->repository = new DoctrineDbalRdbmsEventStoreRepository(
            $connection,
            TableSchemaFactory::createDefaultEventStore(),
            TableSchemaFactory::createDefaultEventStoreRelation(),
            new DoctrineDbalRdbmsEventFactory(),
        );
    }

    #[Test]
    public function itShouldGetEvents(): void
    {
        $this->result->expects('fetchAllAssociative')->andReturn([
            [
                'eventId' => '63129dc3-4a27-4242-a8bc-6f79636a6fa9',
                'eventName'=> 'event_name',
                'payload' => '{"data":"some"}',
                'metadata' => '{"metadata":"some"}',
                'appliedAt' => '2024-12-03 12:05:04.456344',
                'domainId' => '6ae07469-0f43-4f33-979b-c783b6824ce0',
            ],
            [
                'eventId' => '63129dc3-4a27-4242-a8bc-6f79636a6fa9',
                'eventName'=> 'event_name',
                'payload' => '{"data":"some"}',
                'metadata' => '{"metadata":"some"}',
                'appliedAt' => '2024-12-03 12:05:04.456344',
                'domainId' => '0c1ff409-a4be-42f1-90dd-5d7b0130a426',
            ],
            [
                'eventId' => '7ac51abe-9176-4794-8246-24b75c2ba914',
                'eventName'=> 'event_name_2',
                'payload' => '{"data":"another"}',
                'metadata' => '{"metadata":"another"}',
                'appliedAt' => '2024-12-04 13:15:26.755844',
                'domainId' => '6ae07469-0f43-4f33-979b-c783b6824ce0',
            ],
        ]);

        $events = $this->repository->getEvents(
            [
                '072fd355-bd4e-423b-a7ba-fb1a77e32d7c',
                '60a8635c-c769-4d19-8cae-a9571401848f',
            ],
            [
                'event_name',
                'event_name_2',
            ],
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
                new DateTimeImmutable('2024-12-03 12:05:04.456344'),
            ),
            new RdbmsEvent(
                '7ac51abe-9176-4794-8246-24b75c2ba914',
                [
                    '6ae07469-0f43-4f33-979b-c783b6824ce0',
                ],
                'event_name_2',
                '{"data":"another"}',
                ['metadata' => 'another'],
                new DateTimeImmutable('2024-12-04 13:15:26.755844'),
            ),
        ], $events);
    }

    #[Test]
    public function itShouldReturnNullWhenGetLastEventIdPersistedIsNotFound(): void
    {
        $this->result->expects('fetchFirstColumn')->andReturn([]);

        $lastEventIdPersisted = $this->repository->getLastEventIdPersisted(
            [
                '072fd355-bd4e-423b-a7ba-fb1a77e32d7c',
                '60a8635c-c769-4d19-8cae-a9571401848f',
            ],
            [
                'event_name',
                'event_name_2',
            ],
        );

        self::assertNull($lastEventIdPersisted);
    }

    #[Test]
    public function itShouldGetLastEventIdPersisted(): void
    {
        $this->result->expects('fetchFirstColumn')->andReturn(['id' => 'dc99db45-1d1f-4d9d-b52a-83b1cabad89d']);

        $lastEventIdPersisted = $this->repository->getLastEventIdPersisted(
            [
                '072fd355-bd4e-423b-a7ba-fb1a77e32d7c',
                '60a8635c-c769-4d19-8cae-a9571401848f',
            ],
            [
                'event_name',
                'event_name_2',
            ],
        );

        self::assertSame('dc99db45-1d1f-4d9d-b52a-83b1cabad89d', $lastEventIdPersisted);
    }
}
