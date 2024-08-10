<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal;

use Gember\EventSourcing\AggregateRoot\AggregateRootId;
use Gember\EventSourcing\EventStore\EventEnvelope;
use Gember\EventSourcing\EventStore\EventStore;
use Gember\EventSourcing\EventStore\EventStoreFailedException;
use Gember\EventSourcing\EventStore\Metadata;
use Gember\EventSourcing\EventStore\NoEventsForAggregateRootException;
use Gember\EventSourcing\EventStore\OptimisticLockException;
use Gember\EventSourcing\Registry\Event\EventRegistry;
use Gember\EventSourcing\Util\Serialization\Serializer\Serializer;
use Gember\EventSourcing\Util\Time\Clock\Clock;
use Gember\EventStoreDoctrineDbal\Repository\EventStoreRepository;
use Throwable;

final readonly class DoctrineDbalEventStore implements EventStore
{
    public function __construct(
        private EventStoreRepository $repository,
        private Serializer $serializer,
        private EventRegistry $eventRegistry,
        private Clock $clock,
    ) {}

    public function load(AggregateRootId $aggregateRootId, int $playhead = 0): array
    {
        try {
            $rows = $this->repository->getRows($aggregateRootId, $playhead);
        } catch (Throwable $exception) {
            throw EventStoreFailedException::withException($exception);
        }

        if ($rows === []) {
            throw NoEventsForAggregateRootException::withAggregateRootId($aggregateRootId);
        }

        try {
            return array_map(
                fn($row) => new EventEnvelope(
                    $row['eventId'],
                    $this->serializer->deserialize($row['payload'], $this->eventRegistry->retrieve($row['eventName'])),
                    (int) $row['playhead'],
                    $this->clock->now($row['appliedAt']),
                    new Metadata((array) json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR)),
                ),
                $rows,
            );
        } catch (Throwable $exception) {
            throw EventStoreFailedException::withException($exception);
        }
    }

    public function append(AggregateRootId $aggregateRootId, EventEnvelope ...$envelopes): void
    {
        $this->guardOptimisticLock($aggregateRootId, ...$envelopes);

        try {
            $this->repository->saveRows($aggregateRootId, ...$envelopes);
        } catch (Throwable $exception) {
            throw EventStoreFailedException::withException($exception);
        }
    }

    /**
     * @throws OptimisticLockException
     */
    private function guardOptimisticLock(AggregateRootId $aggregateRootId, EventEnvelope ...$envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $firstPlayhead = $envelope->playhead;
            break;
        }

        if (!isset($firstPlayhead)) {
            return;
        }

        $lastPlayheadInStore = $this->repository->getLastPlayhead($aggregateRootId);

        if ($lastPlayheadInStore === null) {
            return;
        }

        if ($lastPlayheadInStore >= $firstPlayhead) {
            throw OptimisticLockException::create();
        }
    }
}
