<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Repository;

use Gember\EventSourcing\AggregateRoot\AggregateRootId;
use Gember\EventSourcing\EventStore\EventEnvelope;

/**
 * @phpstan-type RowPayload array{
 *     eventId: string,
 *     payload: string,
 *     eventName: string,
 *     playhead: string,
 *     appliedAt: string,
 *     metadata: string
 * }
 */
interface EventStoreRepository
{
    /**
     * @return list<RowPayload>
     */
    public function getRows(AggregateRootId $aggregateRootId, int $playhead): array;

    public function saveRows(AggregateRootId $aggregateRootId, EventEnvelope ...$envelopes): void;

    public function getLastPlayhead(AggregateRootId $aggregateRootId): ?int;
}
