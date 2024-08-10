<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Test;

use Gember\EventSourcing\EventStore\EventEnvelope;
use Gember\EventSourcing\EventStore\EventStoreFailedException;
use Gember\EventSourcing\EventStore\Metadata;
use Gember\EventSourcing\EventStore\NoEventsForAggregateRootException;
use Gember\EventSourcing\EventStore\OptimisticLockException;
use Gember\EventStoreDoctrineDbal\DoctrineDbalEventStore;
use Gember\EventStoreDoctrineDbal\Test\TestDoubles\FixedClock;
use Gember\EventStoreDoctrineDbal\Test\TestDoubles\TestAggregateRootId;
use Gember\EventStoreDoctrineDbal\Test\TestDoubles\TestEventRegistry;
use Gember\EventStoreDoctrineDbal\Test\TestDoubles\TestEventStoreRepository;
use Gember\EventStoreDoctrineDbal\Test\TestDoubles\TestSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use LogicException;
use stdClass;
use DateTimeImmutable;

/**
 * @internal
 */
final class DoctrineDbalEventStoreTest extends TestCase
{
    private TestEventStoreRepository $eventStoreRepository;
    private DoctrineDbalEventStore $eventStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventStore = new DoctrineDbalEventStore(
            $this->eventStoreRepository = new TestEventStoreRepository(),
            new TestSerializer(),
            new TestEventRegistry([
                'event.name' => stdClass::class,
            ]),
            new FixedClock(),
        );
    }

    #[Test]
    public function itShouldThrowExceptionOnLoadWhenRepositoryFailed(): void
    {
        $this->eventStoreRepository->addThrows(new LogicException('It failed'));

        self::expectException(EventStoreFailedException::class);
        self::expectExceptionMessage('EventStore request failed: It failed');

        $this->eventStore->load(new TestAggregateRootId());
    }

    #[Test]
    public function itShouldThrowExceptionOnLoadWhenRepositoryReturnsNoRows(): void
    {
        self::expectException(NoEventsForAggregateRootException::class);

        $this->eventStore->load(new TestAggregateRootId());
    }

    #[Test]
    public function itShouldThrowExceptionWhenMappingLoadedRowsToEventEnvelopesFailed(): void
    {
        self::expectException(EventStoreFailedException::class);
        self::expectExceptionMessage('EventStore request failed');

        $this->eventStoreRepository->addRow(
            [
                'eventId' => '7e981b53-4e02-4e49-aa26-a68db45e952b',
                'payload' => '[]]',
                'eventName' => 'invalid.name',
                'playhead' => '1',
                'appliedAt' => '2024-12-12 12:00:00.343',
                'metadata' => '[]',
            ],
        );

        $this->eventStore->load(new TestAggregateRootId());
    }

    #[Test]
    public function itShouldLoadEvents(): void
    {
        $this->eventStoreRepository->addRow(
            [
                'eventId' => '7e981b53-4e02-4e49-aa26-a68db45e952b',
                'payload' => '[]]',
                'eventName' => 'event.name',
                'playhead' => '1',
                'appliedAt' => '2024-12-12 12:00:00.343',
                'metadata' => '[]',
            ],
        );

        $events = $this->eventStore->load(new TestAggregateRootId());

        self::assertEquals([
            new EventEnvelope(
                '7e981b53-4e02-4e49-aa26-a68db45e952b',
                new stdClass(),
                1,
                new DateTimeImmutable('2024-12-12 12:00:00.343'),
                new Metadata(),
            ),
        ], $events);
    }

    #[Test]
    public function itShouldThrowExceptionOnAppendWhenOptimisticLockHappens(): void
    {
        $this->eventStoreRepository->addLastPlayhead(2);

        $this->eventStoreRepository->addThrows(new LogicException('It failed'));

        self::expectException(OptimisticLockException::class);

        $this->eventStore->append(new TestAggregateRootId(), new EventEnvelope(
            '7e981b53-4e02-4e49-aa26-a68db45e952b',
            new stdClass(),
            1,
            new DateTimeImmutable('2024-12-12 12:00:00.343'),
            new Metadata(),
        ));
    }

    #[Test]
    public function itShouldThrowExceptionWhenAppendToRepositoryFailed(): void
    {
        $this->eventStoreRepository->addThrows(new LogicException('It failed'));

        self::expectException(EventStoreFailedException::class);
        self::expectExceptionMessage('EventStore request failed: It failed');

        $this->eventStore->append(new TestAggregateRootId(), new EventEnvelope(
            '7e981b53-4e02-4e49-aa26-a68db45e952b',
            new stdClass(),
            1,
            new DateTimeImmutable('2024-12-12 12:00:00.343'),
            new Metadata(),
        ));
    }

    #[Test]
    public function itShouldAppendEventsToEventStore(): void
    {
        $this->eventStore->append(new TestAggregateRootId(), new EventEnvelope(
            '7e981b53-4e02-4e49-aa26-a68db45e952b',
            new stdClass(),
            1,
            new DateTimeImmutable('2024-12-12 12:00:00.343'),
            new Metadata(),
        ));

        self::assertTrue(true);
    }
}
